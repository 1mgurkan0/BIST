<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;
class GeminiService{
    private $client;
    private $Geminiapikey;
    private LoggerInterface $logger;

    public function __construct(
        HttpClientInterface $client,
        string $Geminiapikey,
        LoggerInterface $logger
    )
    {
        $this->client = $client;
        $this->GeminiApiKey = $Geminiapikey;
        $this->logger = $logger;
    }
    public function ask(string $prompt): string
    {
        $url = "https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=" . $this->GeminiApiKey;

        $response = $this->client->request('POST', $url, [
            'json' => [
                'contents' => [
                    [
                        'parts' => [
                            ['text' =>$prompt
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $result = $response->toArray();
        return $result['candidates'][0]['content']['parts'][0]['text'] ?? 'Analiz hatası oluştu.';
    }


}
