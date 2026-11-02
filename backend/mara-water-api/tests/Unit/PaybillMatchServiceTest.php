<?php

namespace Tests\Unit;

use App\Services\PaybillMatchService;
use PHPUnit\Framework\TestCase;

class PaybillMatchServiceTest extends TestCase
{
    public function test_last9_normalizes_kenyan_phones(): void
    {
        $this->assertSame('712345678', PaybillMatchService::last9('0712345678'));
        $this->assertSame('712345678', PaybillMatchService::last9('254712345678'));
        $this->assertSame('712345678', PaybillMatchService::last9('+254 712 345 678'));
        $this->assertSame('712345678', PaybillMatchService::last9('712345678'));
    }
}
