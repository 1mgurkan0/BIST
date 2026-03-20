<?php


namespace App\Interface;

use App\DTO\MarketDataDto;

interface MarketDataProviderInterface
{
    public function getPrice(string $symbol): ?MarketDataDto;
}