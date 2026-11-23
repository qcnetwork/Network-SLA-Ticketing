<?php

namespace Tests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function userWithRole(Role $role, array $attributes = []): User
    {
        return User::factory()->role($role)->create($attributes);
    }

    /**
     * A repair resume must say whether a device was taken off the customer. Tests about other parts of the
     * resume answer "no" unless they say otherwise; pass 'removed_any' => null to leave it unanswered.
     */
    public function post($uri, array $data = [], array $headers = [])
    {
        if (str_ends_with((string) $uri, '/actions/LAPOR_PERBAIKAN_SELESAI') && ! array_key_exists('removed_any', $data)) {
            $data['removed_any'] = 'tidak';
        }

        return parent::post($uri, $data, $headers);
    }
}
