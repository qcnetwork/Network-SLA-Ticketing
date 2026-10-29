<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Development accounts with well-known passwords. In production only the primary supervisor is
 * created, with a random password shown once, so a fresh server never has guessable logins.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->seedPrimarySupervisor();

            return;
        }

        $accounts = [
            ['username' => 'spv', 'name' => 'Pak Rudi', 'role' => Role::SPV, 'password' => 'spv123', 'phone' => '6281200000001'],
            ['username' => 'helpdesk', 'name' => 'Budi', 'role' => Role::HELPDESK, 'password' => 'hd123', 'phone' => '6281200000002'],
            ['username' => 'cs', 'name' => 'Siti', 'role' => Role::CS, 'password' => 'cs123', 'phone' => '6281200000003'],
            ['username' => 'teknisi', 'name' => 'Agus', 'role' => Role::TEKNISI, 'password' => 'tek123', 'phone' => '6281200000004'],
            ['username' => 'teknisi2', 'name' => 'Rian', 'role' => Role::TEKNISI, 'password' => 'tek123', 'phone' => '6281200000005'],
        ];

        foreach ($accounts as $account) {
            // firstOrCreate: re-running the seeder must never reset a password that was already changed.
            User::query()->firstOrCreate(['username' => $account['username']], $account + ['active' => true]);
        }
    }

    private function seedPrimarySupervisor(): void
    {
        if (User::query()->where('username', User::PRIMARY_SUPERVISOR)->exists()) {
            $this->command?->info('Akun supervisor utama sudah ada, tidak diubah.');

            return;
        }

        $password = Str::password(16, symbols: false);
        User::query()->create([
            'username' => User::PRIMARY_SUPERVISOR,
            'name' => 'Supervisor',
            'role' => Role::SPV,
            'password' => $password,
            'active' => true,
        ]);
        $this->command?->warn('Akun supervisor utama dibuat. Username: '.User::PRIMARY_SUPERVISOR." · Password: {$password}");
        $this->command?->warn('Catat password ini sekarang (tidak ditampilkan lagi), lalu ganti setelah login.');
    }
}
