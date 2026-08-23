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

        $maxLength = 4000;
        if (mb_strlen($message) <= $maxLength) {
            return $this->send($message, $parseMode, 0);
        }

        // Basit chunk mantigi, HTML taglarini bolme riski var ama Telegram limitini asmaktan iyidir.
        // Genelde raporlar satirlardan olustugu icin satir satir bolmeye calisir
        $lines = explode("\n", $message);
        $chunks = [];
        $currentChunk = '';

        foreach ($lines as $line) {
            if (mb_strlen($currentChunk . "\n" . $line) > $maxLength) {
                if ($currentChunk !== '') {
                    $chunks[] = trim($currentChunk);
                    $currentChunk = '';
                }
                
                if (mb_strlen($line) > $maxLength) {
                    $hardChunks = mb_str_split($line, $maxLength);
                    foreach ($hardChunks as $hc) {
                        $chunks[] = $hc;
                    }
                    continue;
                }
            }
            $currentChunk .= ($currentChunk === '' ? '' : "\n") . $line;
        }

        if ($currentChunk !== '') {
            $chunks[] = trim($currentChunk);
        }

        $success = true;
        foreach ($chunks as $chunk) {
            if (!$this->send($chunk, $parseMode, 0)) {
                $success = false;
            }
            usleep(500000); // Spam korumasi
        }

        return $success;
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
