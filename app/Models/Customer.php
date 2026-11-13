<?php

namespace App\Models;

use App\Support\GeoLocation;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer known from earlier tickets, so CS can pick them instead of retyping their details.
 * The code is the customer ID written on tickets.
 */
class Customer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * IDs are matched ignoring letter case, as MySQL's unique index on code does.
     */
    public function scopeWithCode(Builder $query, string $code): Builder
    {
        return $query->whereRaw('lower(code) = ?', [mb_strtolower(trim($code))]);
    }

    /**
     * The same name (ignoring letter case and spacing) and the same WhatsApp number in its stored 628xx form.
     */
    public function scopeWithNameAndPhone(Builder $query, string $name, string $phone): Builder
    {
        $name = mb_strtolower(preg_replace('/\s+/', ' ', trim($name)));

        return $query->where('phone', $phone)->whereRaw('lower(trim(name)) = ?', [$name]);
    }

    /**
     * By ID, name or WhatsApp number; a number typed as 08xx also finds the 628xx it is stored as.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.$term.'%';
        $phone = preg_match('/^[0-9+\-\s()]{4,}$/', $term) ? PhoneNumber::normalize($term) : null;

        return $query->where(fn (Builder $q) => $q->where('code', 'like', $like)
            ->orWhere('name', 'like', $like)
            ->orWhere('phone', 'like', $like)
            ->when($phone, fn (Builder $q) => $q->orWhere('phone', 'like', '%'.$phone.'%')));
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * What a new ticket's address field starts with: the pin when the customer has one, so the ticket
     * is placed on the map, and the written address only when there is no pin.
     */
    public function ticketAddress(): ?string
    {
        return $this->hasLocation() ? GeoLocation::pair($this->latitude, $this->longitude) : $this->address;
    }

    public function mapsUrl(): ?string
    {
        return $this->hasLocation() ? GeoLocation::mapsUrl($this->latitude, $this->longitude) : null;
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'customer_id', 'code');
    }
}
