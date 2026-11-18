<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Partner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The partners (mitra) the warehouse sells equipment to.
 */
class PartnerController extends Controller
{
    public function index(): View
    {
        return view('partners.index', [
            'partners' => Partner::query()
                ->withCount('sales')->withSum('sales', 'total')
                ->withSum(['sales as unpaid_total' => fn ($query) => $query->where('payment_status', PaymentStatus::BELUM_LUNAS)], 'total')
                ->orderByDesc('active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $partner = Partner::query()->create($this->validated($request) + ['active' => true]);

        return back()->with('success', "Mitra {$partner->name} ditambahkan");
    }

    public function update(Request $request, Partner $partner): RedirectResponse
    {
        $partner->update($this->validated($request) + ['active' => $request->boolean('active')]);

        return back()->with('success', "Mitra {$partner->name} diperbarui");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['name' => 'nama mitra', 'phone' => 'nomor WA', 'address' => 'alamat']);
    }
}
