<?php

namespace App\Http\Controllers;

use App\Models\ServicePackage;

class ServicePackageController extends ManagedOptionController
{
    protected function model(): string
    {
        return ServicePackage::class;
    }

    protected function page(): array
    {
        return [
            'route' => 'packages',
            'title' => 'Paket Layanan',
            'description' => 'Pilihan paket saat CS membuat tiket Pasang Baru (PSB). Paket nonaktif tidak muncul di pilihan, tapi tetap tampil di tiket lama.',
            'noun' => 'paket layanan',
            'placeholder' => 'Contoh: Home 20 Mbps',
        ];
    }
}
