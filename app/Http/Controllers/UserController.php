<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', [
            'users' => User::query()->orderBy('role')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('users.form', ['user' => new User(['active' => true]), 'roles' => Role::cases()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150', 'unique:users,email'],
            'role' => ['required', Rule::enum(Role::class)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', Password::min(6)],
        ]);

        $data['phone'] = preg_replace('/\D/', '', (string) ($data['phone'] ?? ''));
        $user = User::create($data + ['active' => true]);

        return redirect()->route('users.index')->with('success', "Pengguna {$user->name} berhasil ditambahkan");
    }

    public function edit(Request $request, User $user): View
    {
        $this->ensureCanEdit($request->user(), $user);

        return view('users.form', ['user' => $user, 'roles' => Role::cases()]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->ensureCanEdit($request->user(), $user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user)],
            'role' => ['required', Rule::enum(Role::class)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', 'string', Password::min(6)],
            'active' => ['required', 'boolean'],
        ]);

        $demotes = $data['role'] !== Role::SPV->value || ! $data['active'];
        if ($demotes && $user->isPrimarySupervisor()) {
            throw ValidationException::withMessages(['role' => 'Akun supervisor utama tidak dapat dinonaktifkan atau diubah perannya']);
        }
        if ($demotes && $user->is($request->user())) {
            throw ValidationException::withMessages(['role' => 'Akun yang sedang dipakai tidak dapat dinonaktifkan atau diubah perannya']);
        }

        $data['phone'] = preg_replace('/\D/', '', (string) ($data['phone'] ?? ''));
        if (blank($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);

        return redirect()->route('users.index')->with('success', "Pengguna {$user->name} berhasil diperbarui");
    }

    /**
     * Only the primary supervisor may edit their own account; otherwise any supervisor could reset its password and take it over.
     */
    private function ensureCanEdit(User $editor, User $user): void
    {
        abort_if($user->isPrimarySupervisor() && ! $editor->isPrimarySupervisor(), 403, 'Hanya supervisor utama yang dapat mengubah akun ini');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->isPrimarySupervisor()) {
            return back()->withErrors(['user' => 'Akun supervisor utama tidak dapat dihapus']);
        }
        if ($user->is($request->user())) {
            return back()->withErrors(['user' => 'Akun yang sedang dipakai tidak dapat dihapus']);
        }

        $user->delete();

        return redirect()->route('users.index')->with('success', "Pengguna {$user->name} berhasil dihapus");
    }
}
