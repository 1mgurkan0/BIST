<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\MarketDataDto;
use App\Entity\Stock;
use App\Repository\KapNewsRepository;
use App\Repository\StockRepository;
use App\Service\YahooFinanceService;
use App\Service\BistUniverseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MarketController extends AbstractController
{
    #[Route('/', name: 'app-home', methods: ['GET'])]
    #[Route('/', name: 'market', methods: ['GET'])]
    public function index(
        Request $request,
        StockRepository $repository,
        YahooFinanceService $yahoo,
        EntityManagerInterface $em,
        KapNewsRepository $kapNewsRepository,
        BistUniverseService $universeService,
    ): Response {
        $symbol = strtoupper(trim((string) $request->query->get('symbol', '')));
        $stock = null;
        $newsList = [];
        $marketDataStatus = null;
        
        $universeSymbols = $universeService->symbols();
        $marketStocks = [];

        if ($symbol !== '' && !preg_match('/^[A-Z0-9]{2,20}$/', $symbol)) {
            $this->addFlash('error', 'Gecerli bir BIST sembolu girin.');
            return $this->redirectToRoute('app-home');
        }

        if ($symbol !== '') {
            $stock = $repository->findRecent($symbol, 60);

            if (!$stock) {
                $quote = $yahoo->fetchOneWithStatus($symbol);
                $dto = $quote['data'] ?? null;
                $marketDataStatus = [
                    'status' => $quote['status'] ?? 'missing_price',
                    'source' => $quote['source'] ?? 'none',
                    'httpStatus' => $quote['httpStatus'] ?? null,
                    'isStale' => (bool) ($quote['isStale'] ?? false),
                    'message' => $quote['message'] ?? null,
                    'lastSuccessfulAt' => ($quote['lastSuccessfulAt'] ?? null) instanceof \DateTimeInterface
                        ? $quote['lastSuccessfulAt']->format(\DateTimeInterface::ATOM)
                        : null,
                ];

                if ($dto instanceof MarketDataDto) {
                    $stock = new Stock();
                    $stock->setSymbol($dto->symbol);
                    $stock->setPrice($dto->price);
                    $stock->setOpen($dto->open);
                    $stock->setHigh($dto->high);
                    $stock->setLow($dto->low);
                    $stock->setPreviousClose($dto->previousClose);
                    $stock->setVolume($dto->volume);
                    $stock->setCreatedAt(\DateTime::createFromImmutable($dto->fetchedAt));

                    if (($quote['status'] ?? null) === 'ok' && !($quote['isStale'] ?? false)) {
                        $em->persist($stock);
                        $em->flush();
                    }
                } else {
                    $stock = $repository->findLatest($symbol);
                    if ($stock instanceof Stock) {
                        $marketDataStatus = [
                            'status' => $quote['status'] ?? 'db_fallback',
                            'source' => 'database_last_success',
                            'httpStatus' => $quote['httpStatus'] ?? null,
                            'isStale' => true,
                            'message' => 'Canli fiyat alinamadi; veritabanindaki son basarili kayit gosteriliyor.',
                            'lastSuccessfulAt' => $stock->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                        ];
                    }
                }
            } else {
                $marketDataStatus = [
                    'status' => 'ok',
                    'source' => 'database_recent',
                    'httpStatus' => null,
                    'isStale' => false,
                    'message' => null,
                    'lastSuccessfulAt' => $stock->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                ];
            }

            $newsList = $kapNewsRepository->findRecentForSymbol(
                $symbol,
                new \DateTimeImmutable('-7 days'),
                10,
            );
        } else {
            // Homepage market view
            $marketStocksAssoc = $repository->findLatestForSymbols($universeSymbols);
            
            // Sort by symbol A-Z
            ksort($marketStocksAssoc);
            
            // Define lists for tabs
            $bist30 = explode(',', 'AKBNK,ALARK,ARCLK,ASELS,ASTOR,BIMAS,BRSAN,CCOL,EKGYO,ENKAI,EREGL,FROTO,GARAN,GUBRF,HEKTS,ISCTR,KCHOL,KONTR,KOZAA,KOZAL,KRDMD,ODAS,OYAKC,PETKM,PGSUS,SAHOL,SASA,SISE,TCELL,THYAO,TOASO,TUPRS,YKBNK');
            $bist50 = explode(',', 'AGHOL,AKSA,ALFAS,BERA,CANTE,CIMSA,CWENE,DOAS,EGEEN,ENJSA,EUPWR,GESAN,HALKB,ISGYO,KORDS,MGROS,MIATK,QUAGR,SMRTG,SOKM,TAVHL,TTKOM,ULKER,VAKBN,YEOTK,ZOREN');
            
            // We consider BIST 100 as Bist30 + Bist50 + the rest inside universe.
            // But let's just categorize based on those explicitly.
            
            $marketStocks = [
                'bist30' => [],
                'bist50' => [],
                'bist100' => [], // Just merge 30 and 50 and some others
                'all' => array_values($marketStocksAssoc),
            ];
            
            foreach ($marketStocksAssoc as $sym => $st) {
                if (in_array($sym, $bist30)) {
                    $marketStocks['bist30'][] = $st;
                    $marketStocks['bist100'][] = $st;
                } elseif (in_array($sym, $bist50)) {
                    $marketStocks['bist50'][] = $st;
                    $marketStocks['bist100'][] = $st;
                } else {
                    $marketStocks['bist100'][] = $st; // Put everything else in 100 for simplicity since they are all big
                }
            }
        }

        return $this->render('market/index.html.twig', [
            'stock' => $stock,
            'symbol' => $symbol,
            'newsList' => $newsList,
            'marketDataStatus' => $marketDataStatus,
            'marketStocks' => $marketStocks,
            'lastUpdate' => !empty($marketStocks['all']) ? $marketStocks['all'][0]->getCreatedAt()->format('H:i:s') : '-',
        ]);
    }
}
