<?php

namespace App\Service;

use App\Entity\KapNews;
use App\Repository\KapNewsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class KapScraperService
{
    private const DISCLOSURE_URL = 'https://www.kap.org.tr/tr/api/disclosure/list/main';
    private const DETAIL_URL = 'https://www.kap.org.tr/tr/Bildirim/%s';

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly EntityManagerInterface $em,
        private readonly KapNewsRepository $repository,
        private readonly LoggerInterface $logger,
        private readonly LockFactory $lockFactory,
    ) {}

    /**
     * @param string[]|null $symbols Null means all BIST company disclosures.
     * @return array{received: int, matched: int, created: int, existing: int, skipped: int, dryRun: bool}
     */
    public function fetchAndSaveLatest(?array $symbols = null, int $days = 2, bool $dryRun = false): array
    {
        $lock = $this->lockFactory->createLock('kap_scraper_process', 120.0, false);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Baska bir KAP taramasi halen calisiyor.');
        }

        try {
            $days = max(1, min(30, $days));
            $allowedSymbols = $symbols === null ? null : array_fill_keys($this->normalizeSymbols($symbols), true);
            $to = new \DateTimeImmutable('today');
            $from = $to->modify(sprintf('-%d days', $days - 1));

            $response = $this->client->request('POST', self::DISCLOSURE_URL, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Accept-Language' => 'tr',
                    'Content-Type' => 'application/json',
                    'Referer' => 'https://www.kap.org.tr/tr',
                    'User-Agent' => 'Mozilla/5.0 (compatible; BAM-Personal-BIST-Terminal/1.0)',
                ],
                'json' => [
                    'fromDate' => $from->format('d.m.Y'),
                    'toDate' => $to->format('d.m.Y'),
                    'disclosureTypes' => null,
                    'memberTypes' => ['IGS'],
                    'mkkMemberOid' => null,
                ],
                'timeout' => 20,
            ]);

            $statusCode = $response->getStatusCode();
            $payload = $response->toArray(false);
            if ($statusCode !== 200 || !array_is_list($payload)) {
                throw new \RuntimeException(sprintf('KAP HTTP %d ile gecersiz cevap dondu.', $statusCode));
            }

            $candidates = [];
            $skipped = 0;
            foreach ($payload as $row) {
                $basic = is_array($row['disclosureBasic'] ?? null) ? $row['disclosureBasic'] : [];
                $kapId = trim((string) ($basic['disclosureIndex'] ?? ''));
                $stockCodes = $this->extractStockCodes($basic);

                if ($kapId === '' || empty($stockCodes)) {
                    $skipped++;
                    continue;
                }

                if ($allowedSymbols !== null && !array_intersect_key(array_fill_keys($stockCodes, true), $allowedSymbols)) {
                    $skipped++;
                    continue;
                }

                $candidates[$kapId] = [
                    'basic' => $basic,
                    'stockCodes' => $stockCodes,
                ];
            }

            $existingIds = $this->repository->findExistingKapIds(array_keys($candidates));
            $existingMap = array_fill_keys($existingIds, true);
            $created = 0;

            foreach ($candidates as $kapId => $candidate) {
                if (isset($existingMap[$kapId])) {
                    continue;
                }

                $basic = $candidate['basic'];
                $stockCodes = $candidate['stockCodes'];
                $company = $this->cleanText((string) ($basic['companyTitle'] ?? ''));
                $title = $this->cleanText((string) ($basic['title'] ?? 'KAP bildirimi'));
                $summary = $this->cleanText((string) ($basic['summary'] ?? ''));
                $url = sprintf(self::DETAIL_URL, rawurlencode($kapId));

                $news = (new KapNews())
                    ->setKapId($kapId)
                    ->setTitle(sprintf('[%s] %s%s', implode(',', $stockCodes), $title, $company === '' ? '' : ' - ' . $company))
                    ->setContent(trim($summary . ' - ' . $url, ' -'))
                    ->setStockCodes($stockCodes)
                    ->setPublishedAt($this->parsePublishDate((string) ($basic['publishDate'] ?? '')))
                    ->setIsAnalyzed(false);

                if (!$dryRun) {
                    $this->em->persist($news);
                }
                $created++;
            }

            if (!$dryRun && $created > 0) {
                $this->em->flush();
            }

            $summary = [
                'received' => count($payload),
                'matched' => count($candidates),
                'created' => $created,
                'existing' => count($existingIds),
                'skipped' => $skipped,
                'dryRun' => $dryRun,
            ];

            $this->logger->info('KAP disclosure refresh completed.', $summary);

            return $summary;
        } catch (\Throwable $e) {
            $this->logger->error('KAP disclosure refresh failed.', ['error' => $e->getMessage()]);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, mixed> $basic
     * @return string[]
     */
    private function extractStockCodes(array $basic): array
    {
        $values = [$basic['stockCode'] ?? null, $basic['relatedStocks'] ?? null];
        $codes = [];

        foreach ($values as $value) {
            $parts = is_array($value) ? $value : (preg_split('/[,;\s]+/', (string) $value) ?: []);
            foreach ($parts as $part) {
                $code = strtoupper(trim((string) $part));
                if (preg_match('/^[A-Z0-9]{2,20}$/', $code)) {
                    $codes[$code] = true;
                }
            }
        }

        return array_keys($codes);
    }

    /**
     * @param string[] $symbols
     * @return string[]
     */
    private function normalizeSymbols(array $symbols): array
    {
        $normalized = [];
        foreach ($symbols as $symbol) {
            $symbol = strtoupper(trim((string) $symbol));
            $symbol = str_ends_with($symbol, '.IS') ? substr($symbol, 0, -3) : $symbol;
            if (preg_match('/^[A-Z0-9]{2,20}$/', $symbol)) {
                $normalized[$symbol] = true;
            }
        }

        return array_keys($normalized);
    }

    private function parsePublishDate(string $value): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone('Europe/Istanbul');
        $date = \DateTimeImmutable::createFromFormat('!d.m.Y H:i:s', trim($value), $timezone);

        return $date ?: new \DateTimeImmutable('now', $timezone);
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim(mb_substr($text, 0, 2000));
    }
}
