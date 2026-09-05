<?php

namespace App\Service;

use App\Interface\AiProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class NvidiaService implements AiProviderInterface
{
    private const ENDPOINT = 'https://integrate.api.nvidia.com/v1/chat/completions';
    private const MAX_ATTEMPTS = 3;
    private const TRANSIENT_STATUS_CODES = [429, 500, 502, 503, 504];
    private const DEFAULT_MODEL = 'nvidia/nemotron-3-ultra-550b-a55b';
    private array $models = [
        'nvidia/nemotron-3-super-120b-a12b',
        'nvidia/nemotron-3-ultra-550b-a55b',
        'moonshotai/kimi-k3'
    ];
    private int $currentModelIndex = 0;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $NvidiaApiKey,
        private readonly LoggerInterface $logger,
        private readonly string $model = self::DEFAULT_MODEL
    ) {}

    public function ask(string $prompt): string
    {
        return $this->request($prompt, false);
    }

    public function askJson(string $prompt): string
    {
        $prompt .= "\n\nPlease ensure your response is in valid JSON format.";
        return $this->request($prompt, true);
    }

    private function request(string $prompt, bool $jsonMode): string
    {
        if (!$this->NvidiaApiKey || $this->NvidiaApiKey === 'CHANGE_ME') {
            throw new \RuntimeException('NVIDIA API key yapilandirilmamis.');
        }

        $payload = [
            'model' => $this->models[$this->currentModelIndex],
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 4096,
            'temperature' => 0.7,
            'chat_template_kwargs' => ['enable_thinking' => false],
        ];

        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $attempt = 1;
        while (true) {
            try {
                $response = $this->client->request('POST', self::ENDPOINT, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->NvidiaApiKey,
                        'Content-Type' => 'application/json',
                        'HTTP-Referer' => 'http://localhost',
                        'X-Title' => 'BAM Terminal',
                    ],
                    'json' => $payload,
                    'timeout' => 150.0,
                ]);

                $statusCode = $response->getStatusCode();

                if ($statusCode >= 200 && $statusCode < 300) {
                    $data = $response->toArray();
                    $content = $data['choices'][0]['message']['content'] ?? '';
                    
                    if ($content === '') {
                        throw new \RuntimeException('NVIDIA bos yanit dondurdu.');
                    }
                    
                    return $content;
                }

                if (!in_array($statusCode, self::TRANSIENT_STATUS_CODES, true)) {
                    throw new \RuntimeException(sprintf('NVIDIA API hatasi: HTTP %d - %s', $statusCode, $response->getContent(false)));
                }

                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new \RuntimeException(sprintf('NVIDIA maksimum deneme asildi (HTTP %d).', $statusCode));
                }

                $this->backoff($attempt, $statusCode);
                ++$attempt;

            } catch (\Throwable $e) {
                $this->logger->error('NVIDIA api error: ' . $e->getMessage());

                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
                
                $this->currentModelIndex = min(count($this->models) - 1, $this->currentModelIndex + 1);
                $this->backoff($attempt, 500);
                ++$attempt;
            }
        }
    }

    private function backoff(int $attempt, int $statusCode): void
    {
        $seconds = $statusCode === 429 ? 15 : ($attempt * 2);
        
        $this->logger->warning('NVIDIA API transient error, backing off.', [
            'attempt' => $attempt,
            'status' => $statusCode,
            'sleep_seconds' => $seconds,
        ]);

        sleep($seconds);
    }
}
