<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The customer directory behind the ticket form's autofill. Tickets keep their own copy of the
 * customer's details and link to a customer through the ID typed on them (tickets.customer_id =
 * customers.code), so the tickets table itself is left as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150)->index();
            $table->string('phone', 30)->index();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->index('customer_id');
        });

        $this->fillFromTickets();
    }

    /**
     * One customer per ID already used on tickets, with the details of its newest ticket. IDs that
     * differ only in letter case or spacing are one customer: MySQL's unique index would treat them so.
     */
    private function fillFromTickets(): void
    {
        $customers = [];
        $tickets = DB::table('tickets')
            ->whereNotNull('customer_id')
            ->where('customer_id', '!=', '')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->select(['customer_id', 'customer_name', 'customer_phone', 'customer_address', 'latitude', 'longitude', 'created_at']);

        foreach ($tickets->cursor() as $ticket) {
            $code = trim($ticket->customer_id);
            $key = mb_strtolower($code);
            if ($code === '') {
                continue;
            }
            if (isset($customers[$key])) {
                // Newest first: the first ticket seen set the details; older ones only move the start date back.
                $customers[$key]['created_at'] = $ticket->created_at;

                continue;
            }
            $customers[$key] = [
                'code' => mb_substr($code, 0, 50),
                'name' => mb_substr($ticket->customer_name, 0, 150),
                'phone' => $ticket->customer_phone,
                'address' => $ticket->customer_address,
                'latitude' => $ticket->latitude,
                'longitude' => $ticket->longitude,
                'created_at' => $ticket->created_at,
                'updated_at' => $ticket->created_at,
            ];
        }

        foreach (array_chunk(array_values($customers), 200) as $batch) {
            DB::table('customers')->insert($batch);
        }
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
        });
        Schema::dropIfExists('customers');
    }
};
