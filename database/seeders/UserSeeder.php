<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Development accounts. Change these passwords before going live.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
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
}
