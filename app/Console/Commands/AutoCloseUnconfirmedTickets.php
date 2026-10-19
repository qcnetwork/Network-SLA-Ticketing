<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use App\Services\TicketWorkflowService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tickets:auto-close')]
#[Description('Tutup otomatis tiket yang tidak ada respon pelanggan 2 hari setelah diselesaikan')]
class AutoCloseUnconfirmedTickets extends Command
{
    public function handle(TicketWorkflowService $workflow, TelegramService $telegram): int
    {
        $closed = $workflow->autoCloseUnconfirmed();

        if ($closed->isNotEmpty()) {
            $telegram->send($telegram->autoClosedMessage($closed), ['action' => 'TUTUP_OTOMATIS']);
        }

        $this->info("{$closed->count()} tiket ditutup otomatis.");

        return self::SUCCESS;
    }
}
