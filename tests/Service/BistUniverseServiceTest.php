<?php

namespace App\Tests\Service;

use App\Service\BistUniverseService;
use PHPUnit\Framework\TestCase;

class BistUniverseServiceTest extends TestCase
{
    public function testItNormalizesAndDeduplicatesConfiguredSymbols(): void
    {
        $symbols = (new BistUniverseService('asels, THYAO.IS; asels invalid-symbol AKBNK'))->symbols();

        self::assertSame(['ASELS', 'THYAO', 'AKBNK'], $symbols);
    }
}
