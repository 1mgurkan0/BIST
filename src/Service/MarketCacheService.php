<?php

namespace App\Service;

use RuntimeException;

class MarketCacheService
{
    private string $cacheFile;

    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.project_dir%')]
        string $projectDir
    )
    {
        $this->cacheFile = rtrim($projectDir, '/') . '/var/market_cache.json';
    }

    public function writeAtomic(array $data): void
    {
        $tmpFile = $this->cacheFile . '.' . uniqid('tmp', true);
        $json = json_encode([
            'updatedAt' => time(),
            'data' => $data
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (file_put_contents($tmpFile, $json) === false) {
            throw new RuntimeException("Failed to write to temporary cache file: $tmpFile");
        }

        if (!rename($tmpFile, $this->cacheFile)) {
            @unlink($tmpFile);
            throw new RuntimeException("Failed to atomic rename temporary cache file to: {$this->cacheFile}");
        }
    }

    public function read(): array
    {
        if (!file_exists($this->cacheFile)) {
            return ['updatedAt' => 0, 'data' => []];
        }

        $json = file_get_contents($this->cacheFile);
        if ($json === false) {
            return ['updatedAt' => 0, 'data' => []];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['updatedAt' => 0, 'data' => []];
        }

        return $data;
    }
}

