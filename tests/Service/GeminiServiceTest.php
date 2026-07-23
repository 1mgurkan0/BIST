<?php

namespace App\Tests\Service;

use App\Service\GeminiService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class GeminiServiceTest extends TestCase
{
    public function testJsonRequestUsesHeaderInsteadOfLeakingKeyInUrl(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertStringNotContainsString('secret-key', $url);
            self::assertContains('x-goog-api-key: secret-key', $options['normalized_headers']['x-goog-api-key']);
            $requestBody = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('application/json', $requestBody['generationConfig']['responseMimeType']);
            self::assertSame(4096, $requestBody['generationConfig']['maxOutputTokens']);
            self::assertSame(0, $requestBody['generationConfig']['thinkingConfig']['thinkingBudget']);

            return new MockResponse(json_encode([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"score":50}']]],
                ]],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $service = new GeminiService($client, 'secret-key', new NullLogger());

        self::assertSame('{"score":50}', $service->askJson('test'));
    }

    public function testTransientServiceFailureIsRetried(): void
    {
        $attempts = 0;
        $client = new MockHttpClient(function () use (&$attempts): MockResponse {
            ++$attempts;

            if ($attempts === 1) {
                return new MockResponse(json_encode([
                    'error' => ['message' => 'Service temporarily unavailable.'],
                ], JSON_THROW_ON_ERROR), ['http_code' => 503]);
            }

            return new MockResponse(json_encode([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"score":75}']]],
                ]],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $service = new GeminiService($client, 'secret-key', new NullLogger());

        self::assertSame('{"score":75}', $service->askJson('test'));
        self::assertSame(2, $attempts);
    }

    public function testExhaustedDailyQuotaIsNotRetried(): void
    {
        $attempts = 0;
        $client = new MockHttpClient(function () use (&$attempts): MockResponse {
            ++$attempts;

            return new MockResponse(json_encode([
                'error' => ['message' => 'Quota exceeded for metric: free_tier_requests. Check billing details.'],
            ], JSON_THROW_ON_ERROR), ['http_code' => 429]);
        });

        $service = new GeminiService($client, 'secret-key', new NullLogger());

        $this->expectException(\RuntimeException::class);
        try {
            $service->askJson('test');
        } finally {
            self::assertSame(1, $attempts);
        }
    }

    public function testShortQuotaWindowIsRetriedAfterSuggestedDelay(): void
    {
        $attempts = 0;
        $client = new MockHttpClient(function () use (&$attempts): MockResponse {
            ++$attempts;
            if ($attempts === 1) {
                return new MockResponse(json_encode([
                    'error' => ['message' => 'Quota exceeded for metric: free_tier_requests. Please retry in 0.01s.'],
                ], JSON_THROW_ON_ERROR), ['http_code' => 429]);
            }

            return new MockResponse(json_encode([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"score":80}']]],
                ]],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $service = new GeminiService($client, 'secret-key', new NullLogger());

        self::assertSame('{"score":80}', $service->askJson('test'));
        self::assertSame(2, $attempts);
    }
}
