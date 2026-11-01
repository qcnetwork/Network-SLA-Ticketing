<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Supervisor pages for a pick-list that tickets store as text (complaint categories, modem types).
 */
abstract class ManagedOptionController extends Controller
{
    /**
     * @return class-string<Model>
     */
    abstract protected function model(): string;

    /**
     * Texts for the page: route (resource name), title, description, noun, placeholder.
     *
     * @return array{route: string, title: string, description: string, noun: string, placeholder: string}
     */
    abstract protected function page(): array;

    public function index(): View
    {
        $model = $this->model();
        $column = $model::TICKET_COLUMN;

        return view('options.index', [
            'page' => $this->page(),
            'options' => $model::query()->ordered()->get(),
            'counts' => Ticket::query()->whereNotNull($column)->selectRaw("{$column} as name, count(*) as total")->groupBy($column)->pluck('total', 'name'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $model = $this->model();
        $page = $this->page();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique((new $model)->getTable(), 'name')],
        ], ['name.unique' => ucfirst($page['noun']).' ini sudah ada'], ['name' => "nama {$page['noun']}"]);

        $option = $model::create([
            'name' => trim($data['name']),
            'sort_order' => (int) $model::query()->max('sort_order') + 10,
            'active' => true,
        ]);

        return redirect()->route("{$page['route']}.index")->with('success', ucfirst($page['noun'])." \"{$option->name}\" berhasil ditambahkan");
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $model = $this->model();
        $page = $this->page();
        $option = $model::query()->findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique($option->getTable(), 'name')->ignore($option)],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'active' => ['required', 'boolean'],
        ], ['name.unique' => ucfirst($page['noun']).' ini sudah ada'], ['name' => "nama {$page['noun']}", 'sort_order' => 'urutan']);

        $name = trim($data['name']);
        DB::transaction(function () use ($option, $data, $name, $model) {
            // Renaming carries the tickets along, so reports keep counting them under one name.
            if ($name !== $option->name) {
                Ticket::query()->where($model::TICKET_COLUMN, $option->name)->update([$model::TICKET_COLUMN => $name]);
            }
            $option->update(['name' => $name, 'sort_order' => $data['sort_order'], 'active' => $data['active']]);
        });

        return redirect()->route("{$page['route']}.index")->with('success', ucfirst($page['noun'])." \"{$option->name}\" berhasil diperbarui");
    }

    public function destroy(int $id): RedirectResponse
    {
        $page = $this->page();
        $option = $this->model()::query()->findOrFail($id);

        // Tickets keep the name as text; deleting a used one would leave them pointing at nothing.
        if ($option->ticketCount() > 0) {
            return back()->withErrors(['option' => ucfirst($page['noun'])." \"{$option->name}\" sudah dipakai tiket. Nonaktifkan saja supaya tidak muncul di pilihan."]);
        }

        $option->delete();

        return redirect()->route("{$page['route']}.index")->with('success', ucfirst($page['noun'])." \"{$option->name}\" berhasil dihapus");
    }
}
