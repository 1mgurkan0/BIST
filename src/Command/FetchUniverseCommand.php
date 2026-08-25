<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: "app:bist:fetch-universe",
    description: "Fetches all BIST symbols from KAP and updates the local universe file."
)]
class FetchUniverseCommand extends Command
{
    public function __construct(private readonly HttpClientInterface $client)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title("BIST Universe Fetcher");

        try {
            $response = $this->client->request("GET", "https://www.kap.org.tr/tr/bist-sirketler", [
                "headers" => [
                    "User-Agent" => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36",
                ],
            ]);

            $html = $response->getContent();
            preg_match_all("/><div>([A-Z0-9]+)<\/div></", $html, $matches);
            $symbols = array_unique($matches[1] ?? []);

            $filtered = [];
            foreach ($symbols as $sym) {
                if (strlen($sym) >= 4 && strlen($sym) <= 6 && !str_contains($sym, " ")) {
                    $filtered[] = $sym;
                }
            }

            if (empty($filtered)) {
                $io->error("No symbols found. KAP HTML format might have changed.");
                return Command::FAILURE;
            }

            $filepath = __DIR__ . "/../../var/bist_universe.txt";
            file_put_contents($filepath, implode(",", $filtered));

            $io->success(sprintf("Successfully fetched and saved %d symbols to %s", count($filtered), $filepath));
            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error("Failed to fetch universe: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
