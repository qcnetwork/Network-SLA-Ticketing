<?php

namespace App\Enums;

/**
 * The menus a supervisor can open or close per user on the access page. Each role gets a set by
 * default; a user's own grants and revocations, stored on the account, override that.
 *
 * Managing users, settings and this access page stays with supervisors and is not listed here,
 * so no grant can let someone hand themselves more access.
 */
enum Menu: string
{
    case ARCHIVE = 'archive';
    case CUSTOMERS = 'customers';
    case REPORTS = 'reports';
    case TELEGRAM_LOGS = 'telegram_logs';
    case WAREHOUSE = 'warehouse';
    case WAREHOUSE_MANAGE = 'warehouse_manage';

    public function label(): string
    {
        return match ($this) {
            self::ARCHIVE => 'Arsip',
            self::CUSTOMERS => 'Pelanggan',
            self::REPORTS => 'Laporan',
            self::TELEGRAM_LOGS => 'Feed Telegram',
            self::WAREHOUSE => 'Gudang (lihat)',
            self::WAREHOUSE_MANAGE => 'Gudang (kelola)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ARCHIVE => 'Cari semua tiket, termasuk yang sudah tutup',
            self::CUSTOMERS => 'Direktori pelanggan',
            self::REPORTS => 'Laporan SLA, export Excel, dan peta area',
            self::TELEGRAM_LOGS => 'Riwayat pesan bot Telegram',
            self::WAREHOUSE => 'Lihat stok gudang dan teknisi',
            self::WAREHOUSE_MANAGE => 'Barang masuk, kirim ke teknisi, retur, ACC material',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::ARCHIVE => 'archive',
            self::CUSTOMERS => 'user',
            self::REPORTS => 'bar-chart-3',
            self::TELEGRAM_LOGS => 'send',
            self::WAREHOUSE, self::WAREHOUSE_MANAGE => 'warehouse',
        };
    }

    /** @return list<Role> */
    public function defaultRoles(): array
    {
        return match ($this) {
            self::ARCHIVE => [Role::HELPDESK, Role::SPV, Role::TEKNISI, Role::CS],
            self::CUSTOMERS => [Role::CS, Role::HELPDESK, Role::SPV],
            self::REPORTS => [Role::HELPDESK, Role::SPV, Role::CS],
            self::TELEGRAM_LOGS => [Role::HELPDESK, Role::SPV],
            self::WAREHOUSE => [Role::GUDANG, Role::SPV, Role::HELPDESK, Role::TEKNISI],
            self::WAREHOUSE_MANAGE => [Role::GUDANG, Role::SPV],
        };
    }

    public function isDefaultFor(Role $role): bool
    {
        return in_array($role, $this->defaultRoles(), true);
    }

    /** @return list<self> */
    public static function defaultsFor(Role $role): array
    {
        return array_values(array_filter(self::cases(), fn (self $menu) => $menu->isDefaultFor($role)));
    }
}
