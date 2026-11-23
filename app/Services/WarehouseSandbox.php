<?php

namespace App\Services;

use App\Enums\ItemKind;
use App\Enums\Role;
use App\Enums\UsagePurpose;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Partner;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Swap the real warehouse data for a dummy set while testing, and put it back afterwards.
 * The real rows are copied to a standalone SQLite file first (the stash), then the warehouse
 * tables are emptied and filled with dummy data made through InventoryService, so it looks like
 * real use. Restoring empties the tables again and copies the stash back, ids and all.
 * Tickets, customers and users are never touched.
 */
class WarehouseSandbox
{
    /** Parents before children, so a restore inserts in a valid order. */
    public const TABLES = [
        'partners', 'item_categories', 'warehouses', 'items', 'sales', 'sale_lines',
        'material_requests', 'material_usages', 'serial_units', 'material_request_lines',
        'material_usage_lines', 'stocks', 'stock_movements', 'stock_adjustments', 'gudang_pelanggan', 'gudang_pelanggan_logs',
    ];

    private const CONNECTION = 'warehouse_stash';

    private const CHUNK = 200;

    public function __construct(private InventoryService $inventory) {}

    public function stashPath(): string
    {
        return storage_path('app/private/warehouse-stash.sqlite');
    }

    public function active(): bool
    {
        return File::exists($this->stashPath());
    }

    /** @return array<string, int> */
    public function liveCounts(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    /** @return array<string, int> */
    public function stashCounts(): array
    {
        $stash = $this->stash();

        // A table added after the stash was made had no real rows then: it comes back empty.
        return collect(self::TABLES)->mapWithKeys(fn ($t) => [$t => $stash->getSchemaBuilder()->hasTable($t) ? $stash->table($t)->count() : 0])->all();
    }

    /**
     * Copy the real warehouse rows to the stash, empty the tables and fill them with dummy data.
     *
     * @return array<string, int> rows stashed per table
     */
    public function enable(User $actor): array
    {
        if ($this->active()) {
            throw new RuntimeException('Mode dummy sudah aktif. Jalankan "inventory:sandbox off" dulu untuk mengembalikan data asli.');
        }

        File::ensureDirectoryExists(dirname($this->stashPath()));
        File::put($this->stashPath(), '');
        try {
            $stash = $this->stash();
            foreach (self::TABLES as $table) {
                $columns = Schema::getColumnListing($table);
                $stash->statement('CREATE TABLE "'.$table.'" ('.collect($columns)->map(fn ($c) => '"'.$c.'"')->implode(', ').')');
                DB::table($table)->orderBy('id')->chunk(self::CHUNK, function ($rows) use ($stash, $table) {
                    $stash->table($table)->insert($rows->map(fn ($row) => (array) $row)->all());
                });
            }
            $counts = $this->stashCounts();
            if ($counts !== $this->liveCounts()) {
                throw new RuntimeException('Salinan data gudang tidak lengkap, dibatalkan. Data asli tidak diubah.');
            }
        } catch (\Throwable $e) {
            $this->dropStash();
            throw $e;
        }

        $this->withoutForeignKeys(function () use ($actor) {
            $this->wipe();
            $this->seedDummy($actor);
        });

        return $counts;
    }

    /**
     * Throw the dummy data away and copy the stashed real rows back.
     *
     * @return array<string, int> rows restored per table
     */
    public function restore(): array
    {
        if (! $this->active()) {
            throw new RuntimeException('Mode dummy tidak aktif, tidak ada data yang perlu dikembalikan.');
        }
        $stash = $this->stash();
        $expected = $this->stashCounts();

        $this->withoutForeignKeys(function () use ($stash, $expected) {
            $this->wipe();
            foreach (self::TABLES as $table) {
                if (! $stash->getSchemaBuilder()->hasTable($table)) {
                    continue;
                }
                // Only columns both sides know, so a migration run while testing does not break the restore.
                $columns = array_values(array_intersect(Schema::getColumnListing($table), $stash->getSchemaBuilder()->getColumnListing($table)));
                $stash->table($table)->select($columns)->orderBy('id')->chunk(self::CHUNK, function ($rows) use ($table) {
                    DB::table($table)->insert($rows->map(fn ($row) => (array) $row)->all());
                });
            }
            if ($this->liveCounts() !== $expected) {
                throw new RuntimeException('Jumlah baris setelah dikembalikan tidak cocok, dibatalkan. Data dummy masih terpasang dan salinan tetap aman.');
            }
        });

        DB::purge(self::CONNECTION);
        File::move($this->stashPath(), storage_path('app/private/warehouse-stash-restored-'.now()->format('Ymd-His').'.sqlite'));

        return $expected;
    }

    private function stash(): Connection
    {
        config(['database.connections.'.self::CONNECTION => [
            'driver' => 'sqlite', 'database' => $this->stashPath(), 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);

        return DB::connection(self::CONNECTION);
    }

    private function dropStash(): void
    {
        DB::purge(self::CONNECTION);
        File::delete($this->stashPath());
    }

    /**
     * SQLite ignores the foreign-key switch inside a transaction, so it is flipped outside it.
     */
    private function withoutForeignKeys(callable $work): void
    {
        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction($work);
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function wipe(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            DB::table($table)->delete();
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::table('sqlite_sequence')->whereIn('name', self::TABLES)->delete();
        }
    }

    private function seedDummy(User $actor): void
    {
        Warehouse::query()->create(['name' => 'Gudang Utama', 'user_id' => null]);

        $category = fn (string $name, string $icon, ItemKind $kind, ?string $prefix, bool $serial, int $order) => ItemCategory::query()->create([
            'name' => $name, 'icon' => $icon, 'kind' => $kind, 'code_prefix' => $prefix, 'requires_serial' => $serial, 'sort_order' => $order,
        ]);
        $modem = $category('Modem / ONT', 'router', ItemKind::PERANGKAT_AKTIF, 'MDM', true, 1);
        $router = $category('Router & Mikrotik', 'server', ItemKind::PERANGKAT_AKTIF, 'RTR', true, 2);
        $fiber = $category('Fiber Optik & Pasif', 'spline', ItemKind::HABIS_PAKAI, 'FBR', false, 3);
        $cable = $category('Kabel', 'cable', ItemKind::KABEL, 'KBL', false, 4);
        $accessory = $category('Aksesoris & Habis Pakai', 'boxes', ItemKind::HABIS_PAKAI, 'AKS', false, 5);
        $tools = $category('Alat Kerja', 'wrench', ItemKind::ASET, 'ALT', false, 6);

        $item = fn (string $code, string $name, ItemCategory $cat, array $extra = []) => Item::query()->create($extra + [
            'code' => $code, 'name' => $name, 'unit' => 'pcs', 'active' => true, 'item_category_id' => $cat->id,
            'kind' => $cat->kind, 'tracks_serial' => $cat->requires_serial, 'scans_mac' => false, 'min_stock' => 0,
        ]);

        $zte = $item('MDM001', 'ONT ZTE F609', $modem, ['scans_mac' => true, 'brand' => 'ZTE', 'variant' => 'ONT GPON', 'min_stock' => 10]);
        $huawei = $item('MDM002', 'ONT HUAWEI HG8245H5', $modem, ['scans_mac' => true, 'brand' => 'Huawei', 'variant' => 'ONT GPON', 'min_stock' => 5]);
        $zimm = $item('MDM003', 'XPON ONU ZIMMLINK', $modem, ['scans_mac' => true, 'brand' => 'Zimmlink', 'variant' => 'ONT XPON', 'min_stock' => 5]);
        $hap = $item('RTR001', 'MIKROTIK HAP LITE RB941', $router, ['brand' => 'Mikrotik', 'min_stock' => 3]);
        $meter = $item('KBL002', 'DROPCORE 1 METER', $cable, ['unit' => 'meter', 'min_stock' => 300]);
        $roll = $item('KBL001', 'DROPCORE 1 ROLL', $cable, ['unit' => 'roll', 'send_as_item_id' => $meter->id, 'send_ratio' => 1000, 'min_stock' => 2]);
        $patch = $item('FBR001', 'PATCH CORD SC-UPC 3M', $fiber, ['min_stock' => 20]);
        $connector = $item('FBR002', 'FAST CONNECTOR SC', $fiber, ['min_stock' => 500]);
        $clamp = $item('AKS001', 'CLAMP S', $accessory, ['min_stock' => 100]);
        $adaptor = $item('AKS002', 'ADAPTOR 12V 1A', $accessory, ['min_stock' => 10]);
        $ladder = $item('ALT001', 'TANGGA LIPAT 3M', $tools);

        $scans = fn (string $prefix, int $count, bool $mac) => collect(range(1, $count))
            ->map(fn () => ['sn' => $prefix.strtoupper(bin2hex(random_bytes(4))), 'mac' => $mac ? strtoupper(bin2hex(random_bytes(6))) : null])->all();

        $this->inventory->receiveSerials($zte, $scans('ZTEGC', 40, true), $actor, 'Dummy: barang datang');
        $this->inventory->receiveSerials($huawei, $scans('HWTC', 25, true), $actor, 'Dummy: barang datang');
        $this->inventory->receiveSerials($zimm, $scans('ZIMM', 15, true), $actor, 'Dummy: barang datang');
        $this->inventory->receiveSerials($hap, $scans('HG0', 10, false), $actor, 'Dummy: barang datang');
        foreach ([[$roll, 8], [$patch, 200], [$connector, 450], [$clamp, 1000], [$adaptor, 60], [$ladder, 4]] as [$counted, $qty]) {
            $this->inventory->receive($counted, $qty, $actor, 'Dummy: stok awal');
        }

        $technicians = User::query()->where('role', Role::TEKNISI)->where('active', true)->orderBy('id')->limit(4)->get();
        $customers = Customer::query()->inRandomOrder()->limit(12)->pluck('code');
        $places = ['Dusun Krajan RT 02', 'Perum Griya Asri Blok C', 'Jl. Raya Sragi No. 14', 'Desa Mulyorejo RT 05', 'Pasar Wage Ruko 3', 'Kampung Baru Gg. Melati'];
        $mainSerials = fn (Item $of, int $take) => $of->serialUnits()->where('warehouse_id', Warehouse::main()->id)->limit($take)->pluck('serial_number')->all();

        foreach ($technicians as $i => $tech) {
            $this->inventory->sendSerials([...$mainSerials($zte, 5), ...$mainSerials($huawei, 3)], $tech, $actor, 'Dummy: bekal teknisi');
            $this->inventory->send($roll, 1, $tech, $actor, 'Dummy: bekal teknisi');
            foreach ([[$patch, 20], [$connector, 40], [$clamp, 100], [$adaptor, 5]] as [$counted, $qty]) {
                $this->inventory->send($counted, $qty, $tech, $actor, 'Dummy: bekal teknisi');
            }

            $own = Warehouse::of($tech);
            foreach (range(0, 1) as $n) {
                $sn = $own->serialUnits()->where('item_id', $zte->id)->value('serial_number');
                $this->inventory->recordUsage([
                    'used_at' => now()->subDays(($i * 3) + ($n * 2) + 1)->toDateString(),
                    'purpose' => $n === 0 ? UsagePurpose::PEMASANGAN_BARU->value : UsagePurpose::GANTI_ALAT->value,
                    'location' => $places[($i * 2 + $n) % count($places)],
                    'team' => $tech->name,
                    'customer_code' => $customers[($i * 2 + $n) % max(1, $customers->count())] ?? null,
                    'removed_device' => $n === 1 ? 'ONT lama rusak (dummy)' : null,
                    'notes' => 'Data dummy',
                ], [
                    ['item' => $zte->code, 'serials' => [$sn]],
                    ['item' => $meter->code, 'quantity' => 120 + 30 * $n],
                    ['item' => $patch->code, 'quantity' => 1],
                    ['item' => $connector->code, 'quantity' => 2],
                    ['item' => $clamp->code, 'quantity' => 4],
                ], $own, $actor);
            }
        }

        if ($technicians->isNotEmpty()) {
            $first = Warehouse::of($technicians->first());
            $this->inventory->returnSerials([$first->serialUnits()->where('item_id', $huawei->id)->value('serial_number')], $first, $actor, 'Dummy: retur, adaptor rusak');
            $this->inventory->returnToMain($clamp, 20, $first, $actor, 'Dummy: sisa bekal');
        }

        $partner = Partner::query()->create(['name' => 'MITRA DUMMY NET', 'phone' => '081200000000', 'notes' => 'Data dummy', 'active' => true]);
        Partner::query()->create(['name' => 'MITRA CONTOH JAYA', 'notes' => 'Data dummy', 'active' => true]);
        $this->inventory->sell(['partner_id' => $partner->id, 'sold_at' => now()->subDays(2)->toDateString(), 'paid' => true, 'notes' => 'Data dummy'], [
            ['item' => $hap->code, 'serials' => $mainSerials($hap, 2), 'price' => 350000],
            ['item' => $connector->code, 'quantity' => 30, 'price' => 5000],
        ], $actor);
        $this->inventory->sell(['buyer_name' => 'Pembeli Umum (dummy)', 'sold_at' => now()->toDateString(), 'paid' => false], [
            ['item' => $zimm->code, 'serials' => $mainSerials($zimm, 1), 'price' => 275000],
        ], $actor);

        // A technician's stock gone minus (more entered than held), to try Adjust Stok on.
        if ($technicians->count() > 1) {
            DB::table('stocks')->where('warehouse_id', Warehouse::of($technicians[1])->id)->where('item_id', $adaptor->id)->update(['quantity' => -3]);
        }

        // Dummy movements must never reach the real warehouse spreadsheet.
        DB::table('stock_movements')->update(['sheet_synced_at' => now()]);
    }
}
