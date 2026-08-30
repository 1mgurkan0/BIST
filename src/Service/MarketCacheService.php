<?php

namespace App\Service;

use RuntimeException;

class MarketCacheService
{
    private string $projectDir;

    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.project_dir%')]
        string $projectDir
    ) {
        $this->projectDir = rtrim($projectDir, '/');
    }

    public function writeAtomicTo(string $filename, array $data): void
    {
        $path = $this->projectDir . '/var/' . $filename;
        $tmpFile = $path . '.' . uniqid('tmp', true);
        $json = json_encode([
            'updatedAt' => time(),
            'data' => $data
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (file_put_contents($tmpFile, $json) === false) {
            throw new RuntimeException("Failed to write to temporary cache file: $tmpFile");
        }

        if (!rename($tmpFile, $path)) {
            @unlink($tmpFile);
            throw new RuntimeException("Failed to atomic rename temporary cache file to: {$path}");
        }
    }

    public function readFrom(string $filename): array
    {
        if (!file_exists($this->projectDir . '/var/' . $filename)) {
            return ['updatedAt' => 0, 'data' => []];
        }

        $json = file_get_contents($this->projectDir . '/var/' . $filename);
        if ($json === false) {
            return ['updatedAt' => 0, 'data' => []];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['updatedAt' => 0, 'data' => []];
        }

        return $data;
    }

    // Geriye dönük uyumluluk
    public function writeAtomic(array $data): void
    {
        $this->writeAtomicTo('market_cache.json', $data);
    }

    public function read(): array
    {
        return $this->readFrom('market_cache.json');
    }
}
