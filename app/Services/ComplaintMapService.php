<?php

namespace App\Services;

use App\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Where complaints come from, for the heatmap. Only tickets with a map pin (a shareloc in the
 * address) can be placed; the rest are counted so the map's coverage is clear.
 */
class ComplaintMapService
{
    /**
     * Grid size for grouping pins into areas: 0.01° is about 1.1 km, roughly a neighbourhood.
     */
    public const CELL_DEGREES = 0.01;

    public const HOTSPOT_LIMIT = 15;

    public function build(Carbon $from, Carbon $to, ?string $category = null): array
    {
        $tickets = Ticket::query()
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($category, fn ($query) => $query->where('category', $category))
            ->latest('created_at')
            ->get(['id', 'ticket_number', 'customer_name', 'customer_address', 'category', 'latitude', 'longitude', 'created_at']);

        $located = $tickets->filter(fn (Ticket $ticket) => $ticket->hasLocation())->values();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'category' => $category,
            'total' => $tickets->count(),
            'located' => $located->count(),
            'points' => $located->map(fn (Ticket $ticket) => [round($ticket->latitude, 5), round($ticket->longitude, 5)])->all(),
            'hotspots' => $this->hotspots($located),
        ];
    }

    /**
     * The grid cells with the most complaints, busiest first.
     *
     * @param  Collection<int, Ticket>  $tickets  newest first
     */
    private function hotspots(Collection $tickets): array
    {
        $cell = fn (float $degrees) => (int) floor($degrees / self::CELL_DEGREES);

        return $tickets
            ->groupBy(fn (Ticket $ticket) => $cell($ticket->latitude).':'.$cell($ticket->longitude))
            ->sortByDesc(fn (Collection $group) => $group->count())
            ->take(self::HOTSPOT_LIMIT)
            ->map(fn (Collection $group) => [
                'count' => $group->count(),
                'latitude' => round($group->avg('latitude'), 5),
                'longitude' => round($group->avg('longitude'), 5),
                'customers' => $group->pluck('customer_name')->unique()->count(),
                'top_category' => $group->countBy('category')->sortDesc()->keys()->first(),
                'address' => $group->map(fn (Ticket $ticket) => self::readableAddress($ticket->customer_address))->filter()->first(),
                'latest' => $group->first()->created_at,
                'tickets' => $group->take(5)->pluck('ticket_number')->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * The written part of an address, without the shareloc link CS often pastes into it.
     */
    private static function readableAddress(?string $address): ?string
    {
        $text = trim((string) preg_replace(['~https?://\S+~i', '~-?\d{1,3}\.\d{3,}\s*,\s*-?\d{1,3}\.\d{3,}~', '~\s+~'], ['', '', ' '], (string) $address), " \t\n,-");

        return $text === '' ? null : Str::limit($text, 80);
    }
}
