<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps a Google Sheet in step with the warehouse: every stock movement is appended to its "Mutasi" tab
 * and the stock rows it touched are refreshed on its "Stok" tab. The sheet side is a small Apps Script
 * web app (docs/google-sheets-gudang.gs) that checks the shared secret.
 *
 * A movement is marked once the sheet has it, so nothing is lost while Google or the internet is
 * down: whatever is still unmarked goes with the next push, which also runs every few minutes.
 */
class SheetSync
{
    private const BATCH = 300;

    public function enabled(): bool
    {
        return filled(Setting::read('sheets_url')) && filled(Setting::read('sheets_secret'));
    }

    /**
     * Send what is pending once the response has gone out, so the page does not wait for Google.
     */
    public function queue(): void
    {
        if ($this->enabled()) {
            defer(fn () => $this->push(), 'sheet-sync', always: true);
        }
    }

    /**
     * Send every movement the sheet does not have yet, oldest first. Returns how many were sent.
     */
    public function push(): int
    {
        if (! $this->enabled()) {
            return 0;
        }
        // Two requests at once would append the same rows twice.
        $lock = Cache::lock('sheet-sync', 120);
        if (! $lock->get()) {
            return 0;
        }

        $sent = 0;
        try {
            while (($movements = $this->pending())->isNotEmpty()) {
                $this->post(['movements' => $movements->map(fn (StockMovement $movement) => $this->movementRow($movement))->all(), 'stock' => $this->stockRows($movements->pluck('item_id')->unique())]);
                StockMovement::query()->whereIn('id', $movements->modelKeys())->update(['sheet_synced_at' => now()]);
                $sent += $movements->count();
            }

            return $sent;
        } catch (Throwable $e) {
            Log::warning('Sinkron Google Sheet gagal, dicoba lagi nanti', ['error' => $e->getMessage()]);

            return $sent;
        } finally {
            $lock->release();
        }
    }

    /**
     * Rewrite the whole "Stok" tab from the stock as it is now, e.g. after an import that placed units without movements.
     */
    public function snapshot(): int
    {
        $rows = $this->stockRows();
        $this->post(['snapshot' => true, 'stock' => $rows]);

        return count($rows);
    }

    /** A test call: the script answers without touching the sheet. */
    public function ping(): void
    {
        $this->post(['ping' => true]);
    }

    public function pendingCount(): int
    {
        return StockMovement::query()->whereNull('sheet_synced_at')->count();
    }

    /** @return Collection<int, StockMovement> */
    private function pending(): Collection
    {
        return StockMovement::query()->whereNull('sheet_synced_at')
            ->with(['item.category', 'serialUnit', 'fromWarehouse', 'toWarehouse', 'ticket', 'sale', 'user'])
            ->oldest('id')->limit(self::BATCH)->get();
    }

    private function post(array $payload): array
    {
        // Apps Script answers a POST with a redirect to where its output can be read.
        $response = Http::timeout(30)->acceptJson()
            ->post((string) Setting::read('sheets_url'), ['secret' => (string) Setting::read('sheets_secret')] + $payload);
        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ($body['ok'] ?? false) !== true) {
            throw new \RuntimeException(is_array($body) && isset($body['error'])
                ? "Spreadsheet menolak: {$body['error']}"
                : "Spreadsheet tidak merespons dengan benar (HTTP {$response->status()}). Cek URL Web App dan aksesnya (Anyone).");
        }

        return $body;
    }

    private function movementRow(StockMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'waktu' => $movement->created_at->format('Y-m-d H:i:s'),
            'jenis' => $movement->type->label(),
            'kode' => $movement->item->code,
            'barang' => $movement->item->name,
            'kategori' => $movement->item->category?->name ?? '',
            'jumlah' => $movement->quantity,
            'satuan' => $movement->item->unit,
            'sn' => $movement->serialUnit?->serial_number ?? '',
            'mac' => $movement->serialUnit?->formattedMac() ?? '',
            'dari' => $movement->fromWarehouse?->name ?? 'Pemasok',
            'ke' => $movement->toWarehouse?->name ?? ($movement->ticket ? 'Pelanggan' : ($movement->sale?->buyer_name ?? '-')),
            'tiket' => $movement->ticket?->ticket_number ?? '',
            'nota' => $movement->sale?->sale_number ?? '',
            'oleh' => $movement->user?->name ?? 'Sistem',
            'keterangan' => $movement->notes ?? '',
        ];
    }

    /**
     * Current stock per warehouse and item, for the given items or for everything.
     *
     * @param  Collection<int, int>|null  $itemIds
     * @return list<array<string, mixed>>
     */
    private function stockRows(?Collection $itemIds = null): array
    {
        return Stock::query()->with(['item.category', 'warehouse'])
            ->when($itemIds, fn ($query) => $query->whereIn('item_id', $itemIds))
            ->get()
            ->sortBy(fn (Stock $stock) => [$stock->warehouse->name, $stock->item->code])
            ->map(fn (Stock $stock) => [
                'gudang' => $stock->warehouse->name,
                'kode' => $stock->item->code,
                'barang' => $stock->item->name,
                'kategori' => $stock->item->category?->name ?? '',
                'stok' => $stock->quantity,
                'satuan' => $stock->item->unit,
                'sn' => $stock->item->tracks_serial ? 'Ya' : '',
            ])->values()->all();
    }
}
