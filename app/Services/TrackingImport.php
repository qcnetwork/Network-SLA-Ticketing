<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Item;
use App\Models\SerialUnit;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Brings the warehouse's "TRACKING ALAT" workbook into the system: one sheet per device type, each
 * holding "PAKET MASUK" blocks with a header row (TANGGAL MASUK, TANGGAL PENGAMBILAN, NAMA BARANG,
 * SN, MAC, TEKNISI, PEMASANGAN ALAT, STATUS, MIGRASI ALAT, ERROR/RETUR, KETERANGAN) and one row per unit.
 *
 * Each SN becomes one unit placed where the sheet says it is now: back at the office, with a
 * technician, installed at a customer, or out of stock as returned or broken. Device types missing
 * from the item master get new MDM codes, and technicians without an account get an inactive one.
 * Running it again updates what an earlier import wrote, but never touches units scanned in the app.
 */
class TrackingImport
{
    /** Spelling variants in the sheet that mean the same technician or team. */
    private const HOLDER_ALIASES = [
        'SIDOEL' => 'SIDOL', 'BOIM' => 'BAIM', 'JR ROSID' => 'ROSED', 'JR ROSED' => 'ROSED', 'ROSID' => 'ROSED',
        'PT. IKM' => 'PT IKM', 'IKM' => 'PT IKM', 'JAKFAR' => 'JAFAR', 'PROJECT JAFAR' => 'JAFAR', 'JAFAR PROJECT' => 'JAFAR',
        'PROJEK JAFAR' => 'JAFAR', 'SIGID' => 'SIGIT', 'SYARIF' => 'SARIF', 'PROJEK MJNET' => 'MJ NET', 'PROJECT MJ NET' => 'MJ NET',
        'MJNET' => 'MJ NET', 'PROJECT RYAN' => 'RYAN', 'RYAN PROJECT' => 'RYAN', 'CPTO' => 'CIPTO', 'CCIPTO' => 'CIPTO',
        'RASID' => 'RASYID', 'ALIP' => 'ALIF', 'ALIPIPAT' => 'ALIF', 'AMIIN' => 'AMIN', 'MUHADI' => 'MOHADI',
        'TM RASYID JR' => 'RASYID JR', 'RSYID JR' => 'RASYID JR',
    ];

    /** @var array<int, Warehouse> each technician's warehouse, looked up once */
    private array $warehouses = [];

    /**
     * @return array{rows: int, units: int, invalid: int, duplicates: int, kept: int, items: list<string>, technicians: list<string>, places: array<string, int>}
     */
    public function import(string $path, bool $dryRun = false): array
    {
        $rows = $this->read($path);
        $invalid = $rows->where('sn', null)->count();
        $valid = $rows->whereNotNull('sn');
        // A unit listed more than once (re-sent, re-checked) is where its latest row says.
        $units = $valid->sortBy(fn (array $row) => [$row['taken_at'] ?? $row['received_at'] ?? '0000-00-00', $row['order']])
            ->keyBy('sn');
        $kept = SerialUnit::query()->whereNull('imported_at')->whereIn('serial_number', $units->keys())->pluck('serial_number');
        $units = $units->except($kept->all());

        $report = [
            'rows' => $rows->count(),
            'units' => $units->count(),
            'invalid' => $invalid,
            'duplicates' => $valid->count() - $valid->pluck('sn')->unique()->count(),
            'kept' => $kept->count(),
            'items' => [],
            'technicians' => [],
            'places' => [],
        ];

        $apply = function () use ($units, &$report) {
            $items = $this->resolveItems($units, $report['items']);
            $holders = $this->resolveHolders($units, $report['technicians']);
            $main = Warehouse::main();
            $now = now();

            $records = $units->map(function (array $row) use ($items, $holders, $main, $now, &$report) {
                [$warehouse, $place] = $this->placeOf($row, $holders, $main);
                $report['places'][$place] = ($report['places'][$place] ?? 0) + 1;

                return [
                    'item_id' => $items[$row['item_key']]->id,
                    'serial_number' => $row['sn'],
                    'mac' => $row['mac'],
                    'warehouse_id' => $warehouse?->id,
                    'received_at' => $row['received_at'],
                    'taken_at' => $row['taken_at'],
                    'holder_name' => $this->cut($row['holder'], 150),
                    'installed_to' => $this->cut($row['installed_to'], 255),
                    'install_status' => $this->cut($row['status'], 100),
                    'replaced_device' => $this->cut($row['replaced'], 150),
                    'condition' => $this->cut($row['condition'], 100),
                    'notes' => $this->cut($row['notes'], 255),
                    'imported_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->values();

            foreach ($records->chunk(500) as $chunk) {
                DB::table('serial_units')->upsert($chunk->all(), ['serial_number'], array_diff(array_keys($chunk->first()), ['serial_number', 'created_at']));
            }
            $this->recountSerialStock();
        };

        if ($dryRun) {
            DB::beginTransaction();
            try {
                $apply();
            } finally {
                DB::rollBack();
            }
        } else {
            DB::transaction($apply);
        }

        return $report;
    }

    /**
     * Every unit row in the workbook, in sheet order. A row whose SN is unusable keeps sn = null.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function read(string $path): Collection
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);

        $rows = collect();
        foreach ($book->getWorksheetIterator() as $sheet) {
            $this->readSheet($sheet, $rows);
        }
        $book->disconnectWorksheets();

        $sheetNames = $rows->where('named', true)->groupBy('sheet')
            ->map(fn ($group) => $group->countBy('name')->sortDesc()->keys()->first());

        return $rows->map(function (array $row) use ($sheetNames) {
            if (! $row['named'] && isset($sheetNames[$row['sheet']])) {
                $row['name'] = $sheetNames[$row['sheet']];
                $row['item_key'] = $this->itemKey($row['name']);
            }

            return $row;
        });
    }

    private function readSheet(Worksheet $sheet, Collection $rows): void
    {
        $columns = null;
        $lastColumn = $sheet->getHighestDataColumn();
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            foreach ($row->getCellIterator('A', $lastColumn) as $cell) {
                // Formulas keep the value Excel last saved; recalculating breaks on lookups into other sheets.
                $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
                $cells[] = is_string($value) ? trim(preg_replace('/\s+/', ' ', $value)) : $value;
            }
            $upper = array_map(fn ($value) => strtoupper((string) $value), $cells);

            if (in_array('SN', $upper, true) && collect($upper)->contains(fn ($value) => str_contains($value, 'NAMA BARANG'))) {
                $columns = $this->columns($upper);

                continue;
            }
            if (! $columns || collect($upper)->contains(fn ($value) => str_contains($value, 'PAKET MASUK'))) {
                continue;
            }
            $get = fn (string $key) => isset($columns[$key]) ? $cells[$columns[$key]] ?? null : null;
            $rawSn = trim((string) $get('sn'));
            if ($rawSn === '') {
                continue;
            }

            $named = $this->text($get('name'));
            $name = $named ?? preg_replace('/^MODEM\s+/i', '', $sheet->getTitle());
            $rows->push([
                'order' => $rows->count(),
                'sheet' => $sheet->getTitle(),
                // An SN with spaces in it is a note typed into the SN column, not a serial number.
                'sn' => preg_match('/^[A-Za-z0-9\-_.:\/]{5,100}$/', $rawSn) ? SerialUnit::normalizeSerial($rawSn) : null,
                'mac' => SerialUnit::normalizeMac((string) $get('mac')),
                'name' => strtoupper($name),
                'named' => $named !== null,
                'item_key' => $this->itemKey($name),
                'holder' => $this->text($get('holder')),
                'installed_to' => $this->text($get('installed_to')),
                'status' => $this->text($get('status')),
                'replaced' => $this->text($get('replaced')),
                'condition' => is_numeric($get('condition')) ? null : $this->text($get('condition')),
                'notes' => is_numeric($get('notes')) ? null : $this->text($get('notes')),
                'received_at' => $this->date($get('received_at')),
                'taken_at' => $this->date($get('taken_at')),
            ]);
        }
    }

    /**
     * @param  array<int, string>  $header
     * @return array<string, int>
     */
    private function columns(array $header): array
    {
        $columns = [];
        foreach ($header as $index => $label) {
            $key = match (true) {
                str_contains($label, 'PENGAMBILAN') => 'taken_at',
                str_contains($label, 'MASUK') && str_starts_with($label, 'TANGGAL') => 'received_at',
                str_contains($label, 'NAMA BARANG') => 'name',
                $label === 'SN' => 'sn',
                $label === 'MAC' => 'mac',
                str_starts_with($label, 'TEKNISI') => 'holder',
                str_starts_with($label, 'PEMASANGAN') => 'installed_to',
                $label === 'STATUS' => 'status',
                str_starts_with($label, 'MIGRASI') => 'replaced',
                str_starts_with($label, 'ERROR') => 'condition',
                str_starts_with($label, 'KETERANGAN') => 'notes',
                default => null,
            };
            if ($key && ! isset($columns[$key])) {
                $columns[$key] = $index;
            }
        }

        return $columns;
    }

    /**
     * Where the unit is now: [warehouse or null, a label for the report].
     *
     * @param  array<string, User>  $holders
     * @return array{0: ?Warehouse, 1: string}
     */
    private function placeOf(array $row, array $holders, Warehouse $main): array
    {
        $condition = strtoupper((string) $row['condition']);
        $holder = $holders[$this->holderName($row['holder']) ?? ''] ?? null;

        return match (true) {
            (bool) preg_match('/KEMBALI|KANTOR|WAREHO|WAREHOU/', $condition) => [$main, 'Kembali ke Gudang Utama'],
            (bool) preg_match('/RETU|EROR|ERROR|RUSAK|SELLER/', $condition.' '.strtoupper((string) $row['holder'])) => [null, 'Keluar stok (retur/eror)'],
            str_contains($condition, 'STOK TEKNISI') && $holder !== null => [$this->warehouseOf($holder), 'Di gudang teknisi'],
            $row['installed_to'] !== null => [null, 'Terpasang di pelanggan'],
            $holder !== null => [$this->warehouseOf($holder), 'Di gudang teknisi'],
            default => [$main, 'Di Gudang Utama'],
        };
    }

    private function warehouseOf(User $user): Warehouse
    {
        return $this->warehouses[$user->id] ??= Warehouse::of($user);
    }

    /**
     * The item for each device name: an existing one whose name matches (ignoring a "MODEM" prefix,
     * spacing and punctuation), or a new SN-tracked item with the next free MDM code.
     *
     * @param  list<string>  $created
     * @return array<string, Item>
     */
    private function resolveItems(Collection $units, array &$created): array
    {
        $known = Item::query()->get()->keyBy(fn (Item $item) => $this->itemKey($item->name));
        $next = (int) Item::query()->where('code', 'like', 'MDM%')->pluck('code')
            ->map(fn (string $code) => (int) (preg_match('/^MDM(\d+)/', $code, $m) ? $m[1] : 0))->max() + 1;

        $items = [];
        foreach ($units->groupBy('item_key') as $key => $group) {
            $item = $known[$key] ?? null;
            if (! $item) {
                $name = $group->countBy('name')->sortDesc()->keys()->first();
                $item = Item::query()->create(['code' => $this->newCode($name, $next), 'name' => mb_substr($name, 0, 150)]);
                $created[] = "{$item->code} {$item->name}";
            }
            if (! $item->tracks_serial) {
                $item->update(['tracks_serial' => true]);
            }
            $items[$key] = $item;
        }

        return $items;
    }

    /**
     * The account for each technician named in the sheet, creating an inactive one when there is none.
     *
     * @param  list<string>  $created
     * @return array<string, User>
     */
    private function resolveHolders(Collection $units, array &$created): array
    {
        $names = $units->pluck('holder')->map(fn ($holder) => $this->holderName($holder))->filter()->unique();
        $users = User::query()->get()->keyBy(fn (User $user) => strtoupper($user->name));
        // A pair written with a space ("BAIM WANTO", "TEGUH KONCO BOS") belongs to its first name when
        // that name also stands on its own; team names like "LADJU NET" stay whole.
        $standalone = $names->reject(fn (string $name) => str_contains($name, ' '))->merge($users->keys())->flip();

        $holders = [];
        foreach ($names as $original) {
            $first = strtok($original, ' ');
            $first = self::HOLDER_ALIASES[$first] ?? $first;
            $name = isset($standalone[$first]) ? $first : $original;
            $user = $holders[$name] ?? $users[$name] ?? null;
            if (! $user) {
                $base = Str::slug($name, '') ?: 'teknisi';
                $username = $base;
                for ($i = 2; User::query()->where('username', $username)->exists(); $i++) {
                    $username = $base.$i;
                }
                $user = User::query()->create([
                    'name' => $name,
                    'username' => $username,
                    'password' => Hash::make(Str::random(40)),
                    'role' => Role::TEKNISI,
                    'active' => false,
                ]);
                $created[] = $name;
            }
            $holders[$name] = $holders[$original] = $user;
        }

        return $holders;
    }

    /**
     * The technician currently holding the unit, from free text like "TIM DIAN > TIM RASYID",
     * "TIM BAIM (WANTO)", "ALI/CIPTO" or "T.JAKFAR". Null when it names no one.
     */
    public function holderName(?string $raw): ?string
    {
        $text = strtoupper(trim((string) $raw));
        if ($text === '' || preg_match('/EROR|ERROR|RETUR|SELLER|RUSAK|^MODEM/', $text)) {
            return null;
        }
        // "A > B" (also typed "A -> B" or "A -} B"): the unit moved on to B.
        $text = collect(preg_split('/\s*(?:-?>|-\})\s*/', $text))->filter()->last() ?? '';
        $text = preg_replace('/\(.*?\)/', '', $text);
        $text = preg_split('/\s*\/\s*|\s+-\s+|-(?=[A-Z])/', trim($text))[0];
        $text = preg_replace('/^(TIM\s+|T\.\s*|P\.\s*)/', '', trim($text));
        $text = trim(preg_replace('/\s+/', ' ', $text), ' .-');
        $text = self::HOLDER_ALIASES[$text] ?? $text;

        return strlen($text) >= 2 && ! is_numeric($text) ? $text : null;
    }

    /**
     * For SN-tracked items the stock count is the number of units sitting in each warehouse.
     */
    private function recountSerialStock(): void
    {
        $itemIds = Item::query()->where('tracks_serial', true)->pluck('id');
        DB::table('stocks')->whereIn('item_id', $itemIds)->delete();
        $now = now();
        DB::table('serial_units')->whereIn('item_id', $itemIds)->whereNotNull('warehouse_id')
            ->selectRaw('warehouse_id, item_id, count(*) as quantity')->groupBy('warehouse_id', 'item_id')->get()
            ->chunk(500)->each(fn ($chunk) => DB::table('stocks')->insert($chunk->map(fn ($row) => [
                'warehouse_id' => $row->warehouse_id,
                'item_id' => $row->item_id,
                'quantity' => $row->quantity,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()));
    }

    private function newCode(string $name, int &$next): string
    {
        if (str_contains($name, 'BATRAI') || str_contains($name, 'BATERAI')) {
            $taken = Item::query()->where('code', 'like', 'BTR%')->pluck('code');
            for ($i = 1; $taken->contains(sprintf('BTR%03d', $i)); $i++);

            return sprintf('BTR%03d', $i);
        }

        return sprintf('MDM%03d', $next++);
    }

    private function itemKey(string $name): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(preg_replace('/^MODEM\s+/i', '', trim($name))));
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || str_starts_with($value, '#') || $value === 'None' ? null : $value;
    }

    private function date(mixed $value): ?string
    {
        if (! is_numeric($value) || $value < 36526 || $value > 55153) { // 2000-01-01 .. 2050-12-31
            return null;
        }
        try {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function cut(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
