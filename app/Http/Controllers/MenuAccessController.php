<?php

namespace App\Http\Controllers;

use App\Enums\Menu;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The supervisor's page for opening or closing menus per user, on top of what their role gives.
 */
class MenuAccessController extends Controller
{
    public function index(): View
    {
        $users = User::query()->orderBy('name')->get()
            ->sortBy(fn (User $user) => array_search($user->role, Role::cases(), true))
            ->groupBy(fn (User $user) => $user->role->value);

        return view('menu-access.index', [
            'groups' => $users,
            'menus' => Menu::cases(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'users' => ['required', 'array'],
            'users.*' => ['integer', Rule::exists('users', 'id')],
            'access' => ['nullable', 'array'],
            'access.*' => ['array'],
            'access.*.*' => [Rule::enum(Menu::class)],
        ]);

        $changed = DB::transaction(function () use ($data) {
            $changed = 0;
            // Every listed user is saved, so one with every box unticked loses every menu.
            foreach (User::query()->whereIn('id', $data['users'])->lockForUpdate()->get() as $user) {
                if ($user->isPrimarySupervisor()) {
                    continue;
                }
                $menus = array_map(fn (string $menu) => Menu::from($menu), $data['access'][$user->id] ?? []);
                $user->setMenus($menus);
                if ($user->isDirty('menu_access')) {
                    $user->save();
                    $changed++;
                }
            }

            return $changed;
        });

        return redirect()->route('menu-access.index')
            ->with('success', $changed ? "Hak akses {$changed} pengguna disimpan" : 'Tidak ada perubahan hak akses');
    }
}
