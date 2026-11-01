<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_number' => 'TCK-'.now()->format('Ymd').'-'.fake()->unique()->numerify('9###'),
            'customer_id' => fake()->numerify('CUST-####'),
            'customer_name' => fake()->name(),
            'customer_phone' => fake()->numerify('62812########'),
            'customer_address' => fake()->address(),
            'category' => fake()->randomElement(['LOS Merah / Kabel Putus', 'Internet Lambat / Ping Tinggi', 'WiFi Sering Putus / Jangkauan Sinyal', 'Lainnya']),
            'priority' => fake()->randomElement(TicketPriority::cases()),
            'complaint' => fake()->sentence(12),
            'status' => TicketStatus::ANTRIAN_HELPDESK,
        ];
    }

    public function status(TicketStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    public function assignedTo(User ...$technicians): static
    {
        return $this->afterCreating(fn (Ticket $ticket) => $ticket->technicians()->attach(collect($technicians)->pluck('id')->all()));
    }
}
