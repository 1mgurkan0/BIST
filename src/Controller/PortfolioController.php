<?php

namespace App\Controller;

use App\Entity\Portfolio;
use App\Entity\Stock;
use App\Repository\KapNewsRepository;
use App\Repository\PortfolioRepository;
use App\Repository\StockRepository;
use App\Service\GeminiService;
use App\Service\PriceSnapshotService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PortfolioController extends AbstractController
{
    public function __construct(
        private PriceSnapshotService $priceSnapshot
    ) {}

    #[Route('/portfolio', name: 'app_portfolio')]
    public function index(PortfolioRepository $repo): Response
    {
        $items              = $repo->findAll();
        $totalValue         = 0;
        $totalCost          = 0;
        $totalDailyChange   = 0;
        $totalPreviousValue = 0;

        $symbols        = array_map(fn($item) => $item->getSymbol(), $items);
        $marketDataMap  = $this->priceSnapshot->itemsForSymbols($symbols);
        $marketStatuses = [];

        foreach ($items as $item) {
            $symbol = strtoupper($item->getSymbol());
            $quoteStatus = $marketDataMap[$symbol] ?? null;
            $marketStatuses[$symbol] = $this->marketStatusPayload($quoteStatus);

            if ($this->hasSnapshotPrice($quoteStatus)) {
                $currentPrice  = (float) $quoteStatus['price'];
                $previousClose = (float) ($quoteStatus['previousClose'] ?? $item->getCostPrice());

                $item->setCurrentPrice($currentPrice);
                $item->setDailyChange($currentPrice - $previousClose);
                $item->setDailyChangePercent($previousClose > 0 ? ($currentPrice - $previousClose) / $previousClose * 100 : 0);
                $item->setTotalValue($item->getLot() * $currentPrice);
                $item->setProfitLoss($item->getTotalValue() - ($item->getLot() * $item->getCostPrice()));
                $item->setProfitLossPercent($item->getCostPrice() > 0
                    ? ($item->getProfitLoss() / ($item->getLot() * $item->getCostPrice())) * 100
                    : 0
                );
                $item->setLastUpdated(new \DateTime());

                $currentTotal  = $item->getLot() * $currentPrice;
                $previousTotal = $item->getLot() * $previousClose;

                $totalValue         += $currentTotal;
                $totalCost          += $item->getLot() * $item->getCostPrice();
                $totalDailyChange   += ($currentTotal - $previousTotal);
                $totalPreviousValue += $previousTotal;
            } else {
                $item->setCurrentPrice($item->getCostPrice());
                $item->setDailyChange(0);
                $item->setDailyChangePercent(0);
                $item->setTotalValue($item->getLot() * $item->getCostPrice());
                $item->setProfitLoss(0);
                $item->setProfitLossPercent(0);
                $item->setLastUpdated(new \DateTime());

                $totalValue += $item->getLot() * $item->getCostPrice();
                $totalCost  += $item->getLot() * $item->getCostPrice();
            }
        }

        $totalProfitLoss         = $totalValue - $totalCost;
        $profitLossPercent       = $totalCost > 0 ? ($totalProfitLoss / $totalCost) * 100 : 0;
        $totalDailyChangePercent = $totalPreviousValue > 0 ? ($totalDailyChange / $totalPreviousValue) * 100 : 0;

        return $this->render('User/portfolio/index.html.twig', [
            'items'                   => $items,
            'totalValue'              => $totalValue,
            'totalProfitLoss'         => $totalProfitLoss,
            'profitLossPercent'       => $profitLossPercent,
            'totalDailyChange'        => $totalDailyChange,
            'totalDailyChangePercent' => $totalDailyChangePercent,
            'marketStatuses'          => $marketStatuses,
        ]);
    }

    #[Route('/portfolio/add', name: 'app_portfolio_add', methods: ['POST'])]
    public function addStock(Request $request, EntityManagerInterface $em): Response
    {
        $symbol = strtoupper(trim((string) $request->get('symbol')));
        $lot    = (int) $request->get('lot');
        $cost   = (float) $request->get('cost');

        $portfolio = new Portfolio();
        $portfolio->setSymbol($symbol);
        $portfolio->setLot($lot);
        $portfolio->setCostPrice($cost);
        $portfolio->setTransactionDate(new \DateTime());
        $portfolio->setLastUpdated(new \DateTime());

        $quoteStatus = $this->priceSnapshot->itemsForSymbols([$symbol])[$symbol] ?? null;

        if ($this->hasSnapshotPrice($quoteStatus)) {
            $currentPrice = (float) $quoteStatus['price'];
            $previousClose = (float) ($quoteStatus['previousClose'] ?? $currentPrice);

            $portfolio->setCurrentPrice($currentPrice);
            $portfolio->setDailyChange($currentPrice - $previousClose);
            $portfolio->setDailyChangePercent(
                $previousClose > 0 ? (($currentPrice - $previousClose) / $previousClose * 100) : 0
            );
            $portfolio->setTotalValue($lot * $currentPrice);
        } else {
            $portfolio->setCurrentPrice((float) $cost);
            $portfolio->setDailyChange(0);
            $portfolio->setDailyChangePercent(0);
            $portfolio->setTotalValue($lot * $cost);
        }

        $portfolio->setProfitLoss($portfolio->getTotalValue() - ($lot * $cost));
        $portfolio->setProfitLossPercent($portfolio->getCostPrice() > 0
            ? ($portfolio->getProfitLoss() / ($lot * $cost)) * 100
            : 0
        );

        $em->persist($portfolio);
        $em->flush();

        return $this->redirectToRoute('app_portfolio');
    }

    #[Route('/portfolio/delete/{id}', name: 'portfolio_delete')]
    public function deleteStock(int $id, PortfolioRepository $repo, EntityManagerInterface $em): Response
    {
        $portfolioItem = $repo->find($id);

        if ($portfolioItem) {
            $em->remove($portfolioItem);
            $em->flush();
        }

        return $this->redirectToRoute('app_portfolio');
    }

    #[Route('/portfolio/edit/{id}', name: 'portfolio_edit', methods: ['POST'])]
    public function editStock(int $id, Request $request, PortfolioRepository $repo, EntityManagerInterface $em): Response
    {
        $portfolioItem = $repo->find($id);

        if (!$portfolioItem) {
            $this->addFlash('error', 'Güncellenecek hisse bulunamadı.');
            return $this->redirectToRoute('app_portfolio');
        }

        $newLot  = $request->get('lot');
        $newCost = $request->get('cost');

        if ($newLot !== null && $newCost !== null) {
            $portfolioItem->setLot((float) $newLot);
            $portfolioItem->setCostPrice((float) $newCost);
            $portfolioItem->setLastUpdated(new \DateTime());
            $em->flush();
            $this->addFlash('success', $portfolioItem->getSymbol() . ' hissesi başarıyla güncellendi.');
        }

        return $this->redirectToRoute('app_portfolio');
    }

    #[Route('/portfolio/analyze/{id}', name: 'portfolio_analyze')]
    public function deepAnalyze(
        int $id,
        PortfolioRepository $portfolioRepo,
        StockRepository $stockRepo,
        KapNewsRepository $kapNewsRepo,
        GeminiService $geminiService,
        EntityManagerInterface $em
    ): Response {
        $portfolioItem = $portfolioRepo->find($id);

        if (!$portfolioItem) {
            $this->addFlash('error', 'Hisse bulunamadı.');
            return $this->redirectToRoute('app_portfolio');
        }

        $symbol    = $portfolioItem->getSymbol();
        $stockData = $stockRepo->findRecent($symbol, 1);

        if (!$stockData) {
            $quoteStatus = $this->priceSnapshot->itemsForSymbols([$symbol])[strtoupper($symbol)] ?? null;

            if ($this->hasSnapshotPrice($quoteStatus)) {
                $stockData = $this->stockFromSnapshot($quoteStatus);
                $em->persist($stockData);
                $em->flush();
            }
        }

        $oneWeekAgo = new \DateTimeImmutable('-1 week');
        $newsList   = $kapNewsRepo->createQueryBuilder('n')
            ->where('n.stockCodes LIKE :symbol')
            ->andWhere('n.publishedAt >= :date')
            ->setParameter('symbol', '%"' . $symbol . '"%')
            ->setParameter('date', $oneWeekAgo)
            ->orderBy('n.publishedAt', 'DESC')
            ->setMaxResults(3)
            ->getQuery()
            ->getResult();

        $technicalText = "Fiyat Verisi Bulunamadı.";
        if ($stockData) {
            $degisim = $stockData->getPrice() - $stockData->getPreviousClose();
            $yuzde   = ($degisim / $stockData->getPreviousClose()) * 100;
            $technicalText = sprintf(
                "Güncel Fiyat: %s TL, Açılış: %s TL, Günlük Değişim: %%%s, Hacim: %s lot.",
                $stockData->getPrice(), $stockData->getOpen(), round($yuzde, 2), $stockData->getVolume()
            );
        }

        $newsText = "";
        if (empty($newsList)) {
            $newsText = "Son 1 haftaya ait KAP haberi veya duyurusu bulunmamaktadır.";
        } else {
            foreach ($newsList as $index => $news) {
                $newsText .= ($index + 1) . ". HABER (" . $news->getPublishedAt()->format('d.m.Y') . "): " . $news->getContent() . "\n";
            }
        }

        $prompt = <<<EOT
# ROL TANIMI
Sen, 15 yıllık deneyime sahip, bir portföy yönetim şirketinde kıdemli başekonomist ve BIST uzmanısın. Yorumların, hem kısa vadeli ticaret fırsatlarını (trade) hem de uzun vadeli değer yatırımı (value investing) prensiplerini harmanlar. Piyasa psikolojisini iyi okur, ancak asla temel dinamiklerden kopmaz.

# VERİ GİRDİLERİ
Aşağıda analizini yapman için gereken tüm veriler bulunmaktadır:

### [HİSSE] $symbol

### [TEKNİK VERİLER - Anlık]
$technicalText
*(Not: Bu veriler içinde anlık fiyat, günlük değişim %, hacim TL/adet ve muhtemelen temel teknik seviyeler bulunmaktadır.)*

### [SON DÖNEM KAP HABERLERİ (1 Hafta)]
$newsText

# ANALİZ GÖREVİN
Bu verileri kullanarak, aşağıdaki 3 aşamalı profesyonel analiz sürecini işlet:

1.  **Kısa Vadeli Momentum (Teknik + Haber):**
    *   **Teknik Durum:** $symbol'un gün içindeki fiyat hareketini ve hacmini değerlendir. Özellikle hacmin, son 1 aylık ortalamaya göre durumu nedir? (Yüksek hacimli yükseliş/düşüş mü, yoksa düşük hacimli bant hareketi mi?)
    *   **Teknik Seviyeler:** Eğer veride varsa, hissenin 5 günlük (haftalık) hareketli ortalamaya göre konumu nedir? Kritik bir destek veya direnç seviyesine yakın mı?
    *   **Haber Etkisi:** Son KAP haberlerini analiz et. Bu haberler olumlu/olumsuz mu, beklentileri mi karşılıyor yoksa sürpriz mi? Haberin fiyat üzerindeki anlık etkisi (varsa) ne yönde?

2.  **Orta-Uzun Vadeli Değerleme (Temel Analiz):**
    *   **Sektörel Konum:** Şirketin faaliyet gösterdiği sektörün (örnek: bankacılık, havacılık, perakende) BIST'teki genel durumu nedir? Sektörde bir daralma/büyüme beklentisi var mı?
    *   **Değerleme (F/K, PD/DD):** Kendi bilgi birikimini kullanarak $symbol'un cari F/K ve PD/DD oranlarını sektör ortalaması ve tarihsel ortalaması ile kıyasla. Hisse bu verilere göre ucuz mu, pahalı mı, yoksa adil fiyatlanmış mı?
    *   **Kârlılık ve Büyüme:** Şirketin kâr marjları, ciro büyümesi ve piyasadaki rekabet gücü hakkında genel bir değerlendirme yap.

3.  **SENTEZ (Kısa + Uzun Vade):**
    *   Yukarıdaki iki analizi birleştir. Kısa vadede teknik olarak baskı altında görünen ama uzun vadede oldukça ucuz bir hisse mi? Yoksa tam tersi mi? Bu tezatlıkları değerlendir.

# ÇIKTI KURALLARI
Bana dönüşünü KESİNLİKLE aşağıdaki JSON formatında, ekstra hiçbir açıklama, yorum veya markdown işareti kullanmadan, sadece ve sadece geçerli bir JSON objesi olarak yap.

Puanlama metodolojin: %60 uzun vadeli temel analiz, %40 kısa vadeli teknik durum.
-100 (Kesinlikle Alınmamalı) ile +100 (Kesinlikle Alınmalı) arasında tam sayı.

{
  "score": (TAM SAYI),
  "summary": "Maksimum 4 cümlelik TÜRKÇE özet."
}
EOT;

        try {
            $aiResponseText = $geminiService->ask($prompt);
            $aiResponseText = trim(str_replace(['```json', '```'], '', $aiResponseText));
            $resultData     = json_decode($aiResponseText, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($resultData['score'])) {
                $portfolioItem->setSentimentScore((int) $resultData['score']);
                $portfolioItem->setAiSummary($resultData['summary'] ?? 'Özet rapor oluşturulamadı.');
                $em->flush();
                $this->addFlash('success', "$symbol derin analizi tamamlandı! Puan: " . $resultData['score']);
            } else {
                $this->addFlash('error', 'Yapay zeka yanıtı anlaşılamadı. Dönen veri formatı hatalı.');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', "Analiz Hatası: " . $e->getMessage());
        }

        return $this->redirectToRoute('app_portfolio');
    }

    #[Route('/api/portfolio/live', name: 'api_portfolio_live', methods: ['GET'])]
    public function liveData(PortfolioRepository $repo): JsonResponse
    {
        $items         = $repo->findAll();
        $symbols       = array_map(fn($item) => $item->getSymbol(), $items);
        $marketDataMap = $this->priceSnapshot->itemsForSymbols($symbols);

        $result         = [];
        $totalValue     = 0;
        $totalCost      = 0;
        $totalDailyChg  = 0;
        $totalPrevValue = 0;

        foreach ($items as $item) {
            $quoteStatus = $marketDataMap[strtoupper($item->getSymbol())] ?? null;

            $currentPrice  = $this->hasSnapshotPrice($quoteStatus) ? (float) $quoteStatus['price'] : $item->getCostPrice();
            $previousClose = $this->hasSnapshotPrice($quoteStatus) ? (float) ($quoteStatus['previousClose'] ?? $currentPrice) : $item->getCostPrice();

            $totalVal  = $item->getLot() * $currentPrice;
            $costTotal = $item->getLot() * $item->getCostPrice();
            $profitLoss = $totalVal - $costTotal;
            $profitLossPct = $costTotal > 0 ? ($profitLoss / $costTotal) * 100 : 0;
            $dailyChg      = $currentPrice - $previousClose;
            $dailyChgPct   = $previousClose > 0 ? ($dailyChg / $previousClose) * 100 : 0;

            $totalValue    += $totalVal;
            $totalCost     += $costTotal;
            $totalDailyChg += $item->getLot() * ($currentPrice - $previousClose);
            $totalPrevValue += $item->getLot() * $previousClose;

            $result[] = array_merge([
                'id'               => $item->getId(),
                'symbol'           => $item->getSymbol(),
                'currentPrice'     => round($currentPrice, 2),
                'totalValue'       => round($totalVal, 2),
                'profitLoss'       => round($profitLoss, 2),
                'profitLossPct'    => round($profitLossPct, 2),
                'dailyChange'      => round($dailyChg, 2),
                'dailyChangePct'   => round($dailyChgPct, 2),
            ], $this->marketStatusPayload($quoteStatus));
        }

        $totalProfitLoss    = $totalValue - $totalCost;
        $profitLossPct      = $totalCost > 0 ? ($totalProfitLoss / $totalCost) * 100 : 0;
        $dailyChangePct     = $totalPrevValue > 0 ? ($totalDailyChg / $totalPrevValue) * 100 : 0;

        return $this->json([
            'items'          => $result,
            'totalValue'     => round($totalValue, 2),
            'totalProfitLoss' => round($totalProfitLoss, 2),
            'profitLossPct'  => round($profitLossPct, 2),
            'totalDailyChg'  => round($totalDailyChg, 2),
            'dailyChangePct' => round($dailyChangePct, 2),
        ]);
    }

    /**
     * @param array<string, mixed>|null $quoteStatus
     * @return array<string, mixed>
     */
    private function marketStatusPayload(?array $quoteStatus): array
    {
        return [
            'quoteStatus' => $quoteStatus['quoteStatus'] ?? ($quoteStatus['status'] ?? 'missing_price'),
            'source' => $quoteStatus['source'] ?? null,
            'httpStatus' => $quoteStatus['httpStatus'] ?? null,
            'statusMessage' => $quoteStatus['statusMessage'] ?? null,
            'isStale' => (bool) ($quoteStatus['isStale'] ?? false),
            'lastSuccessfulPrice' => $quoteStatus['lastSuccessfulPrice'] ?? null,
            'lastSuccessfulAt' => $quoteStatus['lastSuccessfulAt'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed>|null $quoteStatus
     */
    private function hasSnapshotPrice(?array $quoteStatus): bool
    {
        return is_array($quoteStatus) && is_numeric($quoteStatus['price'] ?? null);
    }

    /**
     * @param array<string, mixed> $quoteStatus
     */
    private function stockFromSnapshot(array $quoteStatus): Stock
    {
        $stock = new Stock();
        $stock->setSymbol((string) $quoteStatus['symbol']);
        $stock->setPrice((float) $quoteStatus['price']);
        $stock->setOpen((float) ($quoteStatus['open'] ?? 0));
        $stock->setHigh((float) ($quoteStatus['high'] ?? 0));
        $stock->setLow((float) ($quoteStatus['low'] ?? 0));
        $stock->setPreviousClose((float) ($quoteStatus['previousClose'] ?? 0));
        $stock->setVolume((int) ($quoteStatus['volume'] ?? 0));
        $stock->setCreatedAt(new \DateTime());

        return $stock;
    }
}
