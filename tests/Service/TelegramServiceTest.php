<?php

namespace App\Tests\Service;

use App\Service\TelegramService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class TelegramServiceTest extends TestCase
{
    public function testItSendsTheExpectedTelegramPayload(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.telegram.org/bottest-token/sendMessage', $url);

            $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('123456', $payload['chat_id']);
            self::assertSame('<b>Alarm</b>', $payload['text']);
            self::assertSame('HTML', $payload['parse_mode']);
            self::assertTrue($payload['disable_web_page_preview']);

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });

        $service = new TelegramService('test-token', '123456', $client, new NullLogger());

        self::assertTrue($service->sendMessage('<b>Alarm</b>'));
    }

    public function testMissingCredentialsFailWithoutCallingTelegram(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('Telegram API should not be called without credentials.');
        });
        $service = new TelegramService('', '', $client, new NullLogger());

        self::assertFalse($service->sendMessage('test'));
    }
}
