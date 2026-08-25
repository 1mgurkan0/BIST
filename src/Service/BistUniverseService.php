<?php

namespace App\Service;

class BistUniverseService
{
    public function __construct(
        private readonly string $configuredSymbols,
    ) {}

    /** @return string[] */
    public function symbols(): array
    {
        $symbolString = $this->configuredSymbols;
        $universeFile = __DIR__ . '/../../var/bist_universe.txt';
        if (file_exists($universeFile)) {
            $content = file_get_contents($universeFile);
            if ($content && strlen(trim($content)) > 10) {
                $symbolString = trim($content);
            }
        }

        $symbols = preg_split('/[\s,;]+/', strtoupper($symbolString), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $normalized = [];

        foreach ($symbols as $symbol) {
            $symbol = str_ends_with($symbol, '.IS') ? substr($symbol, 0, -3) : $symbol;
            if (preg_match('/^[A-Z0-9]{2,20}$/', $symbol)) {
                $normalized[$symbol] = true;
            }
        }

        return array_keys($normalized);
    }
}
