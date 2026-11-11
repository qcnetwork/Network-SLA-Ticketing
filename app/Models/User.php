<?php

namespace App\Models;

use App\Enums\Menu;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'username', 'email', 'password', 'role', 'phone', 'active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** Username of the primary supervisor account, which cannot be removed or demoted. */
    public const PRIMARY_SUPERVISOR = 'spv';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'active' => 'boolean',
            'hidden_board_statuses' => 'array',
            'menu_access' => 'array',
        ];
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * The role's menus, adjusted by what a supervisor granted or revoked for this account. The
     * primary supervisor always has everything, so no one can lock the last way back in.
     */
    public function canAccess(Menu $menu): bool
    {
        if ($this->isPrimarySupervisor()) {
            return true;
        }
        // Managing the warehouse happens on its pages, so it brings seeing them along.
        if ($menu === Menu::WAREHOUSE && $this->canAccess(Menu::WAREHOUSE_MANAGE)) {
            return true;
        }

        return $this->menu_access[$menu->value] ?? $menu->isDefaultFor($this->role);
    }

    /**
     * Give this account exactly these menus, keeping only the differences from its role.
     *
     * @param  list<Menu>  $menus
     */
    public function setMenus(array $menus): void
    {
        $overrides = [];
        foreach (Menu::cases() as $menu) {
            $granted = in_array($menu, $menus, true) || ($menu === Menu::WAREHOUSE && in_array(Menu::WAREHOUSE_MANAGE, $menus, true));
            if ($granted !== $menu->isDefaultFor($this->role)) {
                $overrides[$menu->value] = $granted;
            }
        }
        $this->menu_access = $overrides ?: null;
    }

    /** Whether a supervisor changed this account's menus away from its role's. */
    public function hasCustomMenus(): bool
    {
        return ! empty($this->menu_access);
    }

    public function isPrimarySupervisor(): bool
    {
        return $this->username === self::PRIMARY_SUPERVISOR;
    }

    public function scopeTechnicians(Builder $query): Builder
    {
        return $query->where('role', Role::TEKNISI)->where('active', true)->orderBy('name');
    }

    public function assignedTickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'ticket_technician')->withTimestamps();
    }
}
