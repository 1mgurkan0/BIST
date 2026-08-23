<?php

namespace App\Service;

use App\Interface\AiProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GeminiService implements AiProviderInterface
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
    private const MAX_ATTEMPTS = 4;
    private const MAX_RETRY_AFTER_SECONDS = 60.0;
    private const TRANSIENT_STATUS_CODES = [429, 500, 502, 503, 504];

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $Geminiapikey,
        private readonly LoggerInterface $logger,
        private readonly string $model = 'gemini-2.5-flash',
    ) {}

    public function ask(string $prompt): string
    {
        return $this->generate($prompt, false);
    }

    public function askJson(string $prompt): string
    {
        return $this->generate($prompt, true);
    }

    private function generate(string $prompt, bool $json): string
    {
        if (trim($this->Geminiapikey) === '') {
            throw new \RuntimeException('GEMINI_API_KEY tanimli degil.');
        }
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $this->model)) {
            throw new \RuntimeException('GEMINI_MODEL gecersiz.');
        }

        $generationConfig = [
            'temperature' => $json ? 0.15 : 0.3,
            'maxOutputTokens' => 4096,
        ];
        if ($json) {
            $generationConfig['responseMimeType'] = 'application/json';
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => 0];
        }

        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $statusCode = null;
            try {
                $response = $this->client->request('POST', sprintf(self::ENDPOINT, $this->model), [
                    'headers' => [
                        'x-goog-api-key' => $this->Geminiapikey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'contents' => [[
                            'parts' => [['text' => $prompt]],
                        ]],
                        'generationConfig' => $generationConfig,
                    ],
                    'timeout' => 40,
                ]);

                $statusCode = $response->getStatusCode();
                $result = $response->toArray(false);
                if ($statusCode !== 200) {
                    $message = is_string($result['error']['message'] ?? null)
                        ? $result['error']['message']
                        : sprintf('Gemini HTTP %d hatasi.', $statusCode);
                    $lastException = new \RuntimeException($message);

                    $retryAfter = $this->retryAfterSeconds($message);
                    if ($this->isRetryable($statusCode, $message, $retryAfter) && $attempt < self::MAX_ATTEMPTS) {
                        $this->logger->warning('Gemini transient error, request will be retried.', [
                            'status' => $statusCode,
                            'attempt' => $attempt,
                            'retry_after' => $retryAfter,
                        ]);
                        $this->backoff($attempt, $retryAfter);
                        continue;
                    }

                    throw $lastException;
                }

                $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
                if (!is_string($text) || trim($text) === '') {
                    throw new \RuntimeException('Gemini bos veya gecersiz cevap dondu.');
                }

                return trim($text);
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($attempt < self::MAX_ATTEMPTS && $statusCode === null) {
                    $this->logger->warning('Gemini network error, request will be retried.', ['attempt' => $attempt]);
                    $this->backoff($attempt);
                    continue;
                }

                $this->logger->error('Gemini request failed.', ['error' => $e->getMessage()]);
                throw $e;
            }
        }

        throw $lastException ?? new \RuntimeException('Gemini request failed.');
    }

    private function backoff(int $attempt, ?float $retryAfter = null): void
    {
        $delaySeconds = $retryAfter !== null
            ? min(self::MAX_RETRY_AFTER_SECONDS, max(0.1, $retryAfter))
            : (float) (2 ** ($attempt - 1));
        $delayMicroseconds = (int) ceil($delaySeconds * 1_000_000) + random_int(0, 500_000);
        usleep($delayMicroseconds);
    }

    private function isRetryable(int $statusCode, string $message, ?float $retryAfter): bool
    {
        if (!in_array($statusCode, self::TRANSIENT_STATUS_CODES, true)) {
            return false;
        }

        $message = strtolower($message);
        if ($statusCode === 429 && (
            str_contains($message, 'quota exceeded for metric')
            || str_contains($message, 'free_tier_requests')
            || str_contains($message, 'billing details')
        )) {
            return $retryAfter !== null && $retryAfter <= self::MAX_RETRY_AFTER_SECONDS;
        }

        return true;
    }

    private function retryAfterSeconds(string $message): ?float
    {
        if (!preg_match('/retry in\s+([0-9]+(?:\.[0-9]+)?)s/i', $message, $match)) {
            return null;
        }

        return (float) $match[1];
    }
}
