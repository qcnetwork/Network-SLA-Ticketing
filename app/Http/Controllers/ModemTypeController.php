<?php

namespace App\Http\Controllers;

use App\Models\ModemType;

class ModemTypeController extends ManagedOptionController
{
    protected function model(): string
    {
        return ModemType::class;
    }

    protected function page(): array
    {
        return [
            'route' => 'modems',
            'title' => 'Jenis Modem / ONT',
            'description' => 'Saran pilihan saat Helpdesk mencatat modem pelanggan. Helpdesk tetap bisa mengetik tipe lain yang belum ada di daftar. Jenis nonaktif tidak muncul di saran, tapi tetap tampil di tiket lama.',
            'noun' => 'jenis modem',
            'placeholder' => 'Contoh: ZTE F670L V9',
        ];
    }
}
