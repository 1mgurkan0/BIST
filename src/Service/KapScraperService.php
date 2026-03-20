<?php

namespace App\Service;

use App\Entity\KapNews;
use App\Repository\KapNewsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Panther\Client;
use Psr\Log\LoggerInterface;

class KapScraperService
{
    private const KAP_URL = 'https://www.kap.org.tr/tr';

    private const BIST50_STOCKS = [
        'AKBNK', 'ALARK', 'ALFAS', 'ARCLK', 'ASELS', 'ASTOR', 'AHGAZ', 'BIMAS', 'BRSAN',
        'CWENE', 'CVERI', 'DOAS', 'EGEEN', 'EKGYO', 'ENJSA', 'ENKAI', 'EREGL', 'EUPWR',
        'FROTO', 'GARAN', 'GESAN', 'GUBRF', 'HALKB', 'HEKTS', 'ISCTR', 'KCHOL', 'KONTR',
        'KOZAA', 'KOZAL', 'KRDMD', 'MGROS', 'MIATK', 'ODAS', 'OYAKC', 'PETKM', 'PGSUS',
        'SAHOL', 'SASA', 'SISE', 'SMRTG', 'SOKM', 'TAVHL', 'TCELL', 'THYAO', 'TOASO',
        'TTKOM', 'TUPRS', 'VAKBN', 'VESTL', 'YKBNK', 'GENEL', 'BIST'
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private KapNewsRepository $repo,
        private LoggerInterface $logger,
        private LockFactory $lockFactory,
        private TelegramService $telegramService
    ) {}

    public function fetchAndSaveLatest(): void
    {
        $lock = $this->lockFactory->createLock('kap_scraper_process', 600);
        if (!$lock->acquire()) return;

        $client = null;
        try {
            $projectDir = dirname(__DIR__, 2);
            $driverPath = $projectDir . DIRECTORY_SEPARATOR . 'drivers' . DIRECTORY_SEPARATOR . 'chromedriver.exe';

            if (!file_exists($driverPath)) {
                $driverPath = $projectDir . '/drivers/chromedriver';
            }

            $client = Client::createChromeClient($driverPath, [
                '--headless',
                '--no-sandbox',
                '--disable-gpu',
                '--disable-dev-shm-usage',
                '--window-size=1920,1080',
                '--log-level=3',
                '--silent',
                '--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'   ]);

            $this->logger->info("Panther: KAP'a bağlanılıyor...");
            $client->request('GET', self::KAP_URL);

            sleep(15);

            $client->executeScript('window.scrollTo(0, document.body.scrollHeight);');
            sleep(15);

            $crawler = $client->getCrawler();
            $rows = $crawler->filter('tr[id^="notification"]');

            $this->logger->info("DEBUG: Tabloda " . $rows->count() . " adet haber satırı bulundu.");

            $newCount = 0;
            $this->em->beginTransaction();

            $rows->each(function ($row) use (&$newCount) {
                try {
                    $allTds = $row->filter('td');
                    if ($allTds->count() < 8) return;

                    $checkbox = $row->filter('input[type="checkbox"]');
                    if ($checkbox->count() === 0) return;

                    $kapId = $checkbox->attr('id');
                    if (!is_numeric($kapId) || $this->repo->findOneBy(['kapId' => $kapId])) return;

                    $fullUrl = 'https://www.kap.org.tr/tr/Bildirim/' . $kapId;

                    $stockCode = trim($allTds->eq(3)->text());
                    $companyName = trim($allTds->eq(4)->text());
                    $summaryText = trim($allTds->eq(7)->text());
                    $relatedStocks = trim($allTds->eq(8)->text());

                    $finalSymbol = $stockCode;
                    if (empty($finalSymbol) || $finalSymbol === '-') {
                        $finalSymbol = $relatedStocks;
                    }
                    if ((empty($finalSymbol) || $finalSymbol === '-') && str_contains($companyName, 'BORSA İSTANBUL')) {
                        $finalSymbol = 'BIST';
                    }
                    if (empty($finalSymbol) || $finalSymbol === '-') {
                        $finalSymbol = 'GENEL';
                    }

                    $stockArray = array_filter(array_map('trim', explode(',', $finalSymbol)));

                    $isVipStock = false;
                    foreach ($stockArray as $code) {

                        if (in_array(strtoupper($code), self::BIST50_STOCKS)) {
                            $isVipStock = true;
                            break;
                        }
                    }

                    if (!$isVipStock) {
                        return;
                    }

                    $news = new KapNews();
                    $news->setKapId((string)$kapId);
                    $news->setTitle("[" . $finalSymbol . "] " . $this->cleanText($companyName));
                    $news->setContent($this->cleanText($summaryText) . " - " . $fullUrl);
                    $news->setPublishedAt(new \DateTimeImmutable());

                    $stockArray = array_map('trim', explode(',', $finalSymbol));
                    $news->setStockCodes($stockArray);

                    $news->setIsAnalyzed(false);

                    $this->em->persist($news);
                    $newCount++;

                } catch (\Exception $e) {
                }
            });

            $this->em->flush();
            $this->em->commit();
            $this->logger->info("✅ BAŞARILI: $newCount yeni haber veritabanına eklendi.");

        } catch (\Exception $e) {
            if ($this->em->getConnection()->isTransactionActive()) $this->em->rollback();
            $this->handleCriticalError($e);
        } finally {
            if ($client) $client->quit();
            $lock->release();
        }
    }

    private function cleanText(?string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($text ?? ''))));
    }

    private function handleCriticalError(\Exception $e): void
    {
        $cleanMsg = substr(strip_tags($e->getMessage()), 0, 500);
        $this->logger->error("KAP Scraper Kritik Hata: " . $cleanMsg);
        try {
            $this->telegramService->sendMessage("🚨 Scraper Hatası: " . $cleanMsg, 'Markdown');
        } catch (\Throwable $t) {}
    }
}