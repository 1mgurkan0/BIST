<?php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;
class TelegramService{
    private $token;
    private $chatid;
    private HttpClientInterface $client;
    private LoggerInterface $logger;

    public function __construct(
        string $Telegramtoken,
        string $Telegramchatid,
        HttpClientInterface $client,
        LoggerInterface $logger,
    ){
            $this->token = $Telegramtoken;
            $this->chatid = $Telegramchatid;
            $this->client = $client;
            $this->logger = $logger;
    }

    public function sendMessage(string $message, string $parseMode = 'HTML'): bool
    {
        $url = "https://api.telegram.org/bot{$this->token}/sendMessage";

        try {

            $response = $this->client->request('POST', $url, [
                'json' => [
                    'chat_id' => $this->chatid,
                    'text' => $message,
                    'parse_mode' => $parseMode,
                    'disable_web_page_preview' => true
                ],
                'timeout' => 8
            ]);

            $status = $response->getStatusCode();

            if ($status === 429) {
                $data = $response->toArray(false);

                $retryAfter = $data['parameters']['retry_after'] ?? 3;

                $this->logger->warning("Telegram rate limit yedi. {$retryAfter}s sonra tekrar denenecek");

                sleep($retryAfter);

                return $this->sendMessage($message, $parseMode);
            }

            if ($status !== 200) {
                $this->logger->error('Telegram HTTP hata', [
                    'status' => $status,
                    'response' => $response->getContent(false)
                ]);
                return false;
            }

            $content = $response->toArray(false);

            if (($content['ok'] ?? false) !== true) {
                $this->logger->error('Telegram API hata', $content);
                return false;
            }

            $this->logger->info('Telegram mesajı gönderildi');
            return true;

        } catch (\Throwable $e) {

            $this->logger->critical('Telegram exception', [
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

}
