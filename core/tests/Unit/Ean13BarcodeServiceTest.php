<?php

namespace Tests\Unit;

use App\Services\Inventory\Ean13BarcodeService;
use PHPUnit\Framework\TestCase;

class Ean13BarcodeServiceTest extends TestCase
{
    public function test_it_calculates_and_validates_standard_ean_13_check_digits(): void
    {
        $service = new Ean13BarcodeService;

        $this->assertSame(1, $service->calculateCheckDigit('400638133393'));
        $this->assertTrue($service->isValid('4006381333931'));
        $this->assertFalse($service->isValid('4006381333932'));
        $this->assertFalse($service->isValid('30000001'));
    }

    public function test_it_renders_a_scannable_95_module_ean_symbol(): void
    {
        $service = new Ean13BarcodeService;
        $svg = $service->renderSvg('4006381333931');

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('viewBox="0 0 214 54"', $svg);
        $this->assertStringContainsString('aria-label="EAN-13 barcode 4006381333931"', $svg);
    }
}
