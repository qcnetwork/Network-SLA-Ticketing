<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The customer directory for other applications. Anyone with a token reads it; the FTTH mapping app,
 * with a write token, keeps each customer's place in the network (OLT, ODC, ODP, port) up to date.
 * Name, phone, address and pin stay with the ticketing app.
 */
class CustomerController extends Controller
{
    private const NETWORK_RULES = [
        'olt' => ['sometimes', 'nullable', 'string', 'max:100'],
        'odc' => ['sometimes', 'nullable', 'string', 'max:100'],
        'odp' => ['sometimes', 'nullable', 'string', 'max:100'],
        'odp_port' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1024'],
    ];

    /**
     * Customers page by page. ?q= searches ID, name and WhatsApp number; ?updated_since= returns only
     * customers changed after that time, so another application can keep a copy in sync.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'updated_since' => ['nullable', 'date'],
            'has_location' => ['nullable', 'boolean'],
            'olt' => ['nullable', 'string', 'max:100'],
            'odp' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $search = trim((string) ($data['q'] ?? ''));

        $customers = Customer::query()
            ->when($search !== '', fn ($query) => $query->search($search))
            ->when($data['updated_since'] ?? null, fn ($query, $since) => $query->where('updated_at', '>', Carbon::parse($since)))
            ->when(isset($data['has_location']), fn ($query) => $request->boolean('has_location')
                ? $query->whereNotNull('latitude')->whereNotNull('longitude')
                : $query->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude')))
            ->when($data['olt'] ?? null, fn ($query, $olt) => $query->where('olt', $olt))
            ->when($data['odp'] ?? null, fn ($query, $odp) => $query->where('odp', $odp))
            ->orderBy('id')
            ->paginate($data['per_page'] ?? 100)
            ->withQueryString();

        return response()->json([
            'data' => $customers->getCollection()->map(fn (Customer $customer) => $this->present($customer)),
            'meta' => [
                'page' => $customers->currentPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
                'last_page' => $customers->lastPage(),
            ],
            'links' => [
                'next' => $customers->nextPageUrl(),
                'prev' => $customers->previousPageUrl(),
            ],
        ]);
    }

    public function show(string $code): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($code))]);
    }

    /**
     * Set where one customer hangs in the network. Only the fields sent change; null clears one.
     */
    public function updateNetwork(Request $request, string $code): JsonResponse
    {
        $customer = $this->find($code);
        $this->applyNetwork($customer, $request->validate(self::NETWORK_RULES));

        return response()->json(['data' => $this->present($customer->refresh())]);
    }

    /**
     * Many customers at once, e.g. after mapping a whole ODP or for the first sync. IDs that are not in
     * the directory are listed back instead of failing the whole batch.
     */
    public function updateNetworkBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customers' => ['required', 'array', 'min:1', 'max:500'],
            'customers.*.id' => ['required', 'string', 'max:50'],
            ...Arr::mapWithKeys(self::NETWORK_RULES, fn ($rules, $field) => ["customers.*.{$field}" => $rules]),
        ]);

        $updated = 0;
        $notFound = [];
        DB::transaction(function () use ($data, &$updated, &$notFound) {
            foreach ($data['customers'] as $item) {
                $customer = Customer::query()->withCode($item['id'])->first();
                if (! $customer) {
                    $notFound[] = $item['id'];

                    continue;
                }
                $this->applyNetwork($customer, Arr::only($item, array_keys(self::NETWORK_RULES)));
                $updated++;
            }
        });

        return response()->json(['updated' => $updated, 'not_found' => $notFound]);
    }

    private function find(string $code): Customer
    {
        $customer = Customer::query()->withCode($code)->first();
        abort_unless($customer, 404, 'Pelanggan tidak ditemukan');

        return $customer;
    }

    /** @param  array<string, mixed>  $network */
    private function applyNetwork(Customer $customer, array $network): void
    {
        $customer->fill(array_map(fn ($value) => is_string($value) ? (trim($value) ?: null) : $value, $network));
        if ($customer->isDirty()) {
            $customer->network_updated_at = now();
            $customer->save();
        }
    }

    /** @return array<string, mixed> */
    private function present(Customer $customer): array
    {
        return [
            'id' => $customer->code,
            'name' => $customer->name,
            'phone' => $customer->phone ?: null,
            'address' => $customer->address,
            'latitude' => $customer->latitude,
            'longitude' => $customer->longitude,
            'maps_url' => $customer->mapsUrl(),
            'network' => [
                'olt' => $customer->olt,
                'odc' => $customer->odc,
                'odp' => $customer->odp,
                'odp_port' => $customer->odp_port,
                'updated_at' => $customer->network_updated_at?->toIso8601String(),
            ],
            'created_at' => $customer->created_at?->toIso8601String(),
            'updated_at' => $customer->updated_at?->toIso8601String(),
        ];
    }
}
