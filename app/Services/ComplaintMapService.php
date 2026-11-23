<?php

namespace App\Services;

use App\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Where complaints come from, for the complaint map: a marker per customer location (tikor) and
 * the busiest areas with who complained there and how often. Only tickets with a map pin (a
 * shareloc in the address) can be placed; the rest are counted so the map's coverage is clear.
 */
class ComplaintMapService
{
    /**
     * Grid size for grouping pins into areas: 0.01° is about 1.1 km, roughly a neighbourhood.
     */
    public const CELL_DEGREES = 0.01;

    public const HOTSPOT_LIMIT = 15;

    /**
     * A pin further than this (in degrees, ~110 km) from where most complaints are is taken as a typo,
     * usually a latitude missing its minus sign: listed for fixing and left out of the map's first view.
     */
    public const STRAY_DEGREES = 1.0;

    /**
     * Tickets listed in a marker's popup per customer.
     */
    public const MARKER_TICKET_LIMIT = 5;

    public function build(Carbon $from, Carbon $to, ?string $category = null): array
    {
        $tickets = Ticket::query()->complaints()
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($category, fn ($query) => $query->where('category', $category))
            ->latest('created_at')
            ->get(['id', 'ticket_number', 'customer_id', 'customer_name', 'customer_address', 'category', 'latitude', 'longitude', 'created_at']);

        $located = $tickets->filter(fn (Ticket $ticket) => $ticket->hasLocation())->values();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'category' => $category,
            'total' => $tickets->count(),
            'located' => $located->count(),
            'markers' => $this->markers($located),
            'strays' => $this->strays($located),
            'hotspots' => $this->hotspots($located),
        ];
    }

    /**
     * Tickets pinned far from the middle (the median) of all pins, most likely a mistyped coordinate.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return Collection<int, Ticket>
     */
    private function strays(Collection $tickets): Collection
    {
        if ($tickets->count() < 3) {
            return collect();
        }
        $median = fn (string $key) => $tickets->pluck($key)->sort()->values()[intdiv($tickets->count(), 2)];
        [$latitude, $longitude] = [$median('latitude'), $median('longitude')];

        return $tickets->filter(fn (Ticket $ticket) => abs($ticket->latitude - $latitude) > self::STRAY_DEGREES
            || abs($ticket->longitude - $longitude) > self::STRAY_DEGREES)->values();
    }

    /**
     * One marker per coordinate (rounded to ~1 m), with every customer who complained from it.
     *
     * @param  Collection<int, Ticket>  $tickets  newest first
     */
    private function markers(Collection $tickets): array
    {
        return $tickets
            ->groupBy(fn (Ticket $ticket) => round($ticket->latitude, 5).','.round($ticket->longitude, 5))
            ->map(fn (Collection $group) => [
                'latitude' => round($group->first()->latitude, 5),
                'longitude' => round($group->first()->longitude, 5),
                'count' => $group->count(),
                'address' => self::addressOf($group),
                'customers' => $this->complainants($group, self::MARKER_TICKET_LIMIT, withCategories: true),
            ])
            ->sortBy('count') // busiest drawn last, so they sit on top
            ->values()
            ->all();
    }

    /**
     * The grid cells with the most complaints, busiest first. A cell's count is the sum of the markers inside it.
     *
     * @param  Collection<int, Ticket>  $tickets  newest first
     */
    private function hotspots(Collection $tickets): array
    {
        // From the coordinate as its marker has it (5 decimals), so a pin on a cell's edge counts in the same
        // cell its marker is drawn in; the inner round keeps -7.25 / 0.01 from landing at -725.0000001.
        $cell = fn (float $degrees) => (int) floor(round(round($degrees, 5) / self::CELL_DEGREES, 6));

        return $tickets
            ->groupBy(fn (Ticket $ticket) => $cell($ticket->latitude).':'.$cell($ticket->longitude))
            ->sortByDesc(fn (Collection $group) => $group->count())
            ->take(self::HOTSPOT_LIMIT)
            ->map(fn (Collection $group) => [
                'count' => $group->count(),
                // The grid cell itself, drawn on the map so it shows which markers the count adds up.
                'bounds' => [
                    [round($cell($group->first()->latitude) * self::CELL_DEGREES, 5), round($cell($group->first()->longitude) * self::CELL_DEGREES, 5)],
                    [round(($cell($group->first()->latitude) + 1) * self::CELL_DEGREES, 5), round(($cell($group->first()->longitude) + 1) * self::CELL_DEGREES, 5)],
                ],
                // How many markers (distinct tikor) the area holds.
                'points' => $group->map(fn (Ticket $ticket) => round($ticket->latitude, 5).','.round($ticket->longitude, 5))->unique()->count(),
                'latitude' => round($group->avg('latitude'), 5),
                'longitude' => round($group->avg('longitude'), 5),
                'top_category' => $group->countBy('category')->sortDesc()->keys()->first(),
                'address' => self::addressOf($group),
                'latest' => $group->first()->created_at,
                'complainants' => $this->complainants($group),
            ])
            ->values()
            ->all();
    }

    /**
     * Who complained within a group of tickets and how often, most complaints first.
     *
     * @param  Collection<int, Ticket>  $tickets  newest first
     */
    private function complainants(Collection $tickets, ?int $ticketLimit = null, bool $withCategories = false): array
    {
        return $tickets
            ->groupBy(fn (Ticket $ticket) => $ticket->customer_id ?: 'name:'.Str::lower(trim($ticket->customer_name)))
            ->map(function (Collection $group) use ($ticketLimit, $withCategories) {
                $latest = $group->first();
                $listed = $ticketLimit ? $group->take($ticketLimit) : $group;

                return [
                    'name' => $latest->customer_name,
                    'customer_id' => $latest->customer_id,
                    'count' => $group->count(),
                    'latitude' => round($latest->latitude, 5),
                    'longitude' => round($latest->longitude, 5),
                    'latest' => $latest->created_at->format('d/m/Y'),
                    'tickets' => $listed->map(fn (Ticket $ticket) => array_filter([
                        'number' => $ticket->ticket_number,
                        'category' => $withCategories ? $ticket->category : null,
                        'date' => $withCategories ? $ticket->created_at->format('d/m/Y') : null,
                    ]))->values()->all(),
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     */
    private static function addressOf(Collection $tickets): ?string
    {
        return $tickets->map(fn (Ticket $ticket) => self::readableAddress($ticket->customer_address))->filter()->first();
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
