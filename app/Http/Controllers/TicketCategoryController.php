<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TicketCategoryController extends Controller
{
    public function index(): View
    {
        $counts = Ticket::query()->selectRaw('category, count(*) as total')->groupBy('category')->pluck('total', 'category');

        return view('categories.index', [
            'categories' => TicketCategory::query()->ordered()->get(),
            'counts' => $counts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:ticket_categories,name'],
        ], ['name.unique' => 'Kategori ini sudah ada'], ['name' => 'nama kategori']);

        $category = TicketCategory::create([
            'name' => trim($data['name']),
            'sort_order' => (int) TicketCategory::query()->max('sort_order') + 10,
            'active' => true,
        ]);

        return redirect()->route('categories.index')->with('success', "Kategori \"{$category->name}\" berhasil ditambahkan");
    }

    public function update(Request $request, TicketCategory $category): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('ticket_categories', 'name')->ignore($category)],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'active' => ['required', 'boolean'],
        ], ['name.unique' => 'Kategori ini sudah ada'], ['name' => 'nama kategori', 'sort_order' => 'urutan']);

        $name = trim($data['name']);
        DB::transaction(function () use ($category, $data, $name) {
            // Renaming carries the tickets along, so reports keep counting them under one category.
            if ($name !== $category->name) {
                Ticket::query()->where('category', $category->name)->update(['category' => $name]);
            }
            $category->update(['name' => $name, 'sort_order' => $data['sort_order'], 'active' => $data['active']]);
        });

        return redirect()->route('categories.index')->with('success', "Kategori \"{$category->name}\" berhasil diperbarui");
    }

    public function destroy(TicketCategory $category): RedirectResponse
    {
        // Tickets keep the category as text; deleting a used one would leave them pointing at nothing.
        if ($category->ticketCount() > 0) {
            return back()->withErrors(['category' => "Kategori \"{$category->name}\" sudah dipakai tiket. Nonaktifkan saja supaya tidak muncul di pilihan."]);
        }

        $category->delete();

        return redirect()->route('categories.index')->with('success', "Kategori \"{$category->name}\" berhasil dihapus");
    }
}
