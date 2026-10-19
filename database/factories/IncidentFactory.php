<?php

namespace Database\Factories;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'incident_number' => 'GMS-'.now()->format('Ymd').'-'.fake()->unique()->numerify('9#'),
            'title' => 'LOS massal '.fake()->streetName(),
            'area' => 'ODP '.fake()->bothify('??-##'),
            'cause' => fake()->sentence(),
            'status' => IncidentStatus::AKTIF,
        ];
    }

    public function status(IncidentStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    public function assignedTo(User ...$technicians): static
    {
        return $this->afterCreating(fn (Incident $incident) => $incident->technicians()->attach(collect($technicians)->pluck('id')->all()));
    }
}
