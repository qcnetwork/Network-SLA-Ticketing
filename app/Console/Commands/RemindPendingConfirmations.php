<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Services\TelegramService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tickets:remind-confirmations')]
#[Description('Kirim reminder ke grup Telegram agar CS follow up tiket yang menunggu konfirmasi pelanggan')]
class RemindPendingConfirmations extends Command
{
    public function handle(TelegramService $telegram): int
    {
        $tickets = Ticket::query()->awaitingConfirmation()->orderBy('resolved_at')->get();

        if ($tickets->isEmpty()) {
            $this->info('Tidak ada tiket yang menunggu konfirmasi.');

            return self::SUCCESS;
        }

        $telegram->send($telegram->confirmationReminderMessage($tickets), ['action' => 'REMINDER_KONFIRMASI']);
        $this->info("Reminder dikirim untuk {$tickets->count()} tiket.");

        return self::SUCCESS;
    }
}
