<?php

namespace App\Console\Commands;

use App\Services\TelegramTicketList;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tickets:telegram-list')]
#[Description('Perbarui master list tiket di topic Telegram; di hari baru, kirim pesan list baru')]
class RefreshTelegramTicketList extends Command
{
    public function handle(TelegramTicketList $list): int
    {
        $list->refresh();
        $this->info('Master list tiket diperbarui.');

        return self::SUCCESS;
    }
}
