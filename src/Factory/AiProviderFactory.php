<?php

namespace App\Factory;

use App\Interface\AiProviderInterface;
use App\Service\GeminiService;
use App\Service\NvidiaService;

class AiProviderFactory
{
    public function __construct(
        private readonly GeminiService $gemini,
        private readonly NvidiaService $nvidia,
        private readonly string $activeProvider
    ) {}

    public function create(): AiProviderInterface
    {
        return strtolower($this->activeProvider) === 'nvidia' ? $this->nvidia : $this->gemini;
    }
}
