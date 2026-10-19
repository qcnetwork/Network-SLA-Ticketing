<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_numbers_are_normalized_to_international_format(): void
    {
        $this->assertSame('6281234567890', PhoneNumber::normalize('0812-3456-7890'));
        $this->assertSame('6281234567890', PhoneNumber::normalize('+62 812 3456 7890'));
        $this->assertSame('6281234567890', PhoneNumber::normalize('6281234567890'));
        $this->assertSame('', PhoneNumber::normalize(null));
    }
}
