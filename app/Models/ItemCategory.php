<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of items in the warehouse (modems, switches, cable, poles, connectors...). A category that requires
 * an SN (devices, but also dropcore rolls or poles with their own barcode) has each of its items received,
 * sent and returned by scanning. Whether a MAC is scanned too is set per item.
 */
class ItemCategory extends Model
{
    protected $guarded = ['id'];

    /**
     * The categories a new install starts with: [name, Lucide icon, requires SN].
     */
    public const DEFAULTS = [
        ['Modem / ONT', 'router', true],
        ['Router & Mikrotik', 'server', true],
        ['Switch', 'network', true],
        ['Wireless & Access Point', 'wifi', true],
        ['OLT & SFP', 'radio-tower', true],
        ['Fiber Optik & Pasif', 'cable', false],
        ['Kabel', 'spline', false],
        ['Kelistrikan', 'plug-zap', false],
        ['Aksesoris & Habis Pakai', 'boxes', false],
        ['Alat Kerja', 'wrench', false],
    ];

    /**
     * Icons a category may use, with what each shows; every one is registered in resources/js/icons.js.
     */
    public const ICONS = [
        'package' => 'Umum',
        'router' => 'Modem / router',
        'server' => 'Server / rak',
        'network' => 'Switch / jaringan',
        'wifi' => 'Wireless',
        'radio-tower' => 'Tower / OLT',
        'cable' => 'Konektor / fiber',
        'spline' => 'Kabel',
        'plug-zap' => 'Kelistrikan',
        'battery' => 'Baterai',
        'boxes' => 'Aksesoris',
        'wrench' => 'Alat kerja',
        'cpu' => 'Modul / chip',
        'hard-drive' => 'Penyimpanan',
        'monitor' => 'Monitor / CCTV',
    ];

    /**
     * Keywords that place an item in a default category, checked in order (fiber parts and
     * cable ties before cable, an SC adapter before a power adapter).
     */
    private const KEYWORDS = [
        'Modem / ONT' => ['MDM', 'MODEM', ' ONT', ' ONU', 'HG6', 'HG8', 'F609', 'F660', 'F670'],
        'OLT & SFP' => ['OLT', 'SFP', 'MEDIA CONVERTER', 'HTB'],
        'Router & Mikrotik' => ['MIKROTIK', 'ROUTERBOARD', 'RB750', 'RB951', 'RB4011', 'CCR', ' HAP', 'ROUTER'],
        'Switch' => ['SWITCH', 'HUB'],
        'Wireless & Access Point' => ['ACCESS POINT', 'UBIQUITI', 'LITEBEAM', 'NANOSTATION', 'RADIO', 'UNIFI', ' EAP'],
        'Fiber Optik & Pasif' => ['SPLITTER', 'ODP', 'ODC', 'CLOSURE', 'PATCHCORD', 'PATCH CORD', 'PIGTAIL', 'KONEKTOR', 'CONNECTOR', 'FAST CONN', 'ADAPTER SC', 'ADAPTOR SC', 'SC/UPC', 'SC/APC', 'ROSET', 'OTB'],
        'Aksesoris & Habis Pakai' => ['KLEM', 'CLAMP', 'CABLE TIE', 'ISOLASI', 'SELONGSONG', 'SLEEVE', 'BREKET', 'BRACKET', 'PAKU', 'HOOK', 'SKUN', 'RJ45', 'RJ 45'],
        'Kabel' => ['KABEL', 'CABLE', 'DROPCORE', 'DROP CORE', ' UTP', 'FO '],
        'Alat Kerja' => ['SPLICER', 'CLEAVER', ' OPM', 'OTDR', ' TANG', 'STRIPPER', 'LASER', 'TOOL'],
        'Kelistrikan' => ['ADP', 'ADAPTOR', 'ADAPTER', ' UPS', 'BATERAI', 'BATTERY', 'POWER', 'PSU', 'POE'],
    ];

    protected function casts(): array
    {
        return ['requires_serial' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * The default category an item most likely belongs to, from its code and name.
     */
    public static function guessName(?string $code, ?string $name): ?string
    {
        $text = ' '.strtoupper(trim("{$code} {$name}")).' ';
        // A cable named after what it plugs into ("KABEL SFP", "KABEL LAN") is still a cable, counted by length.
        if (str_starts_with(strtoupper(trim((string) $code)), 'KBL') || preg_match('/^\s*KABEL\b/i', (string) $name)) {
            return 'Kabel';
        }
        foreach (self::KEYWORDS as $category => $words) {
            foreach ($words as $word) {
                if (str_contains($text, $word)) {
                    return $category;
                }
            }
        }

        return null;
    }

    public static function guess(?string $code, ?string $name): ?self
    {
        $name = self::guessName($code, $name);

        return $name ? static::query()->where('name', $name)->first() : null;
    }
}
