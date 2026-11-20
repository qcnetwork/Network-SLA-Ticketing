<?php

namespace App\Services;

use App\Enums\ItemKind;

/**
 * Reads an item's jenis (ONT GPON / EPON / XPON...), merk and sifat out of its warehouse name, so imported
 * or old items land in the category → jenis → merk tree without typing each one in. A guess only fills what
 * is empty; anything it cannot read stays "Belum ditentukan" for the warehouse to set on the item.
 */
class ItemClassifier
{
    /**
     * Brand keywords as they appear in names, with how the brand is shown. The earliest one in a name wins
     * ("CHINA UNICOM / HUAWEI ..." is China Unicom).
     */
    private const BRANDS = [
        'FIBERHOME' => 'Fiberhome',
        'FIBER HOME' => 'Fiberhome',
        'MY LINK' => 'MyLink',
        'HUAWEI' => 'Huawei',
        'HUWAEI' => 'Huawei',
        'ZTE' => 'ZTE',
        'ZXHN' => 'ZTE',
        'ZIMMLINK' => 'Zimmlink',
        'HSGQ' => 'HSGQ',
        'CDATA' => 'C-Data',
        'C-DATA' => 'C-Data',
        'GGCLINK' => 'GGCLink',
        'BDCOM' => 'BDCOM',
        'TPLINK' => 'TP-Link',
        'TP-LINK' => 'TP-Link',
        'TP LINK' => 'TP-Link',
        'TENDA' => 'Tenda',
        'TOTOLINK' => 'Totolink',
        'FASTLINK' => 'Fastlink',
        'TMTECH' => 'TMTech',
        'MYLINK' => 'MyLink',
        'HSAIRPO' => 'HSAirpo',
        'CHINA MOBILE' => 'China Mobile',
        'CHINA UNICOM' => 'China Unicom',
        'NETIS' => 'Netis',
        'DLINK' => 'D-Link',
        'D-LINK' => 'D-Link',
        'MIKROTIK' => 'Mikrotik',
        'ROUTERBOARD' => 'Mikrotik',
        'UBIQUITI' => 'Ubiquiti',
        'UNIFI' => 'Ubiquiti',
        'RUIJIE' => 'Ruijie',
        'REYEE' => 'Ruijie',
        'CISCO' => 'Cisco',
        'HIOSO' => 'Hioso',
        'VSOL' => 'VSOL',
        'MERCUSYS' => 'Mercusys',
        'XIAOMI' => 'Xiaomi',
        'NOKIA' => 'Nokia',
        'HIKVISION' => 'Hikvision',
        'DAHUA' => 'Dahua',
    ];

    /**
     * Models whose brand the name leaves out.
     */
    private const MODEL_BRANDS = [
        '/\bF(609|660|670|680|450)/' => 'ZTE',
        '/\bHG6(045|145|821|823)/' => 'Fiberhome',
        '/\bAN5506/' => 'Fiberhome',
        '/\b(HG8245|HS8145|HG8546|HG8145|EG8021|HS8346|H8245)/' => 'Huawei',
        '/\bG(99|667)\b/' => 'GGCLink',
    ];

    /**
     * Jenis for the modem / ONT category: a PON type named outright, then models known to be one.
     */
    private const MODEM_VARIANTS = [
        'ONT XPON' => ['/XPON/', '/\bF663N/', '/\bG99\b/', '/\bGS3101\b/', '/\bTJXP/', '/\bXPW\d/', '/\bXC220/', '/\bG663\b/'],
        'ONT EPON' => ['/EPON/'],
        'ONT GPON' => ['/GPON/', '/\bF(609|660|670|680|450)/', '/\bHG6(045|145|821|823)/', '/\b(HG8245|HS8145|HG8546|HG8145|EG8021|HS8346|H8245)/',
            '/\bGM(220|620)/', '/\bTM-?(8546|670)/', '/\b8546\b/', '/\bAN5506/', '/\bGP1704/'],
        'Hotspot / Router WiFi' => ['/HOTSPOT/', '/TOTOLINK/'],
    ];

    /**
     * The sifat a category's items start with, by keywords in the category name.
     */
    private const CATEGORY_KINDS = [
        ['/ASET|ASSET|TIDAK HABIS|ALAT KERJA|TIANG/', ItemKind::ASET],
        ['/KABEL|CABLE/', ItemKind::KABEL],
        ['/MODEM|ONT|ROUTER|MIKROTIK|SWITCH|WIRELESS|ACCESS POINT|OLT|SFP|SERVER/', ItemKind::PERANGKAT_AKTIF],
    ];

    public function brand(string $name): ?string
    {
        $upper = ' '.strtoupper($name).' ';
        $found = null;
        $at = PHP_INT_MAX;
        foreach (self::BRANDS as $keyword => $brand) {
            $position = strpos($upper, $keyword);
            if ($position !== false && $position < $at) {
                [$found, $at] = [$brand, $position];
            }
        }
        if ($found) {
            return $found;
        }
        foreach (self::MODEL_BRANDS as $pattern => $brand) {
            if (preg_match($pattern, $upper)) {
                return $brand;
            }
        }

        return null;
    }

    /**
     * The jenis of an item in the given category, or null when the name does not tell (or the category has none).
     */
    public function variant(?string $categoryName, string $name): ?string
    {
        if (! $categoryName || ! preg_match('/MODEM|ONT/i', $categoryName)) {
            return null;
        }
        $upper = ' '.strtoupper($name).' ';
        foreach (self::MODEM_VARIANTS as $variant => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $upper)) {
                    return $variant;
                }
            }
        }

        return null;
    }

    public function kind(?string $categoryName): ItemKind
    {
        $upper = strtoupper((string) $categoryName);
        foreach (self::CATEGORY_KINDS as [$pattern, $kind]) {
            if (preg_match($pattern, $upper)) {
                return $kind;
            }
        }

        return ItemKind::HABIS_PAKAI;
    }
}
