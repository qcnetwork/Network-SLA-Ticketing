<?php

namespace App\Support;

class PhoneNumber
{
    /**
     * Keep digits only and convert local 08xx numbers to the 628xx format used by wa.me.
     */
    public static function normalize(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;
    }
}
