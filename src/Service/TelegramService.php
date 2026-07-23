<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelegramService
{
    private const MAX_RETRIES = 2;
    private const MAX_MESSAGE_LENGTH = 4096;

    public function __construct(
        private readonly string $Telegramtoken,
        private readonly string $Telegramchatid,
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendMessage(string $message, string $parseMode = 'HTML'): bool
    {
        if (trim($this->Telegramtoken) === '' || trim($this->Telegramchatid) === '') {
            $this->logger->warning('Telegram credentials are missing.');
            return false;
        }

        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $this->logger->error('Telegram message exceeds 4096 characters.', ['length' => mb_strlen($message)]);
            return false;
        }

        return $this->send($message, $parseMode, 0);
    }

    private function send(string $message, string $parseMode, int $attempt): bool
    {
        $url = sprintf('https://api.telegram.org/bot%s/sendMessage', $this->Telegramtoken);

        try {
            $response = $this->client->request('POST', $url, [
                'json' => [
                    'chat_id' => $this->Telegramchatid,
                    'text' => $message,
                    'parse_mode' => $parseMode,
                    'disable_web_page_preview' => true,
                ],
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            $content = $response->toArray(false);

            if ($status === 429 && $attempt < self::MAX_RETRIES) {
                $retryAfter = max(1, min(15, (int) ($content['parameters']['retry_after'] ?? 3)));
                $this->logger->warning('Telegram rate limited the message.', [
                    'retry_after' => $retryAfter,
                    'attempt' => $attempt + 1,
                ]);
                sleep($retryAfter);

                return $this->send($message, $parseMode, $attempt + 1);
            }

            if ($status !== 200 || ($content['ok'] ?? false) !== true) {
                $this->logger->error('Telegram API request failed.', [
                    'status' => $status,
                    'description' => $content['description'] ?? null,
                ]);
                return false;
            }

            $this->logger->info('Telegram message sent.');
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Telegram request failed.', [
                'error' => str_replace($this->Telegramtoken, '[redacted]', $e->getMessage()),
            ]);
            return false;
        }
    }
}
