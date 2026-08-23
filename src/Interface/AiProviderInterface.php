<?php

namespace App\Interface;

interface AiProviderInterface
{
    /**
     * Ask a question and get a plain string response.
     */
    public function ask(string $prompt): string;

    /**
     * Ask a question and get a JSON string response.
     */
    public function askJson(string $prompt): string;
}
