<?php

namespace App\Http\Controllers;

use App\Models\TicketCategory;

class TicketCategoryController extends ManagedOptionController
{
    protected function model(): string
    {
        return TicketCategory::class;
    }

    protected function page(): array
    {
        return [
            'route' => 'categories',
            'title' => 'Kategori Kendala',
            'description' => 'Pilihan kategori saat membuat tiket dan saat Helpdesk/Teknisi mengubah kategori. Kategori nonaktif tidak muncul di pilihan, tapi tetap tampil di tiket lama dan laporan.',
            'noun' => 'kategori',
            'placeholder' => 'Contoh: Pindah Lokasi / Relokasi',
        ];
    }
}
