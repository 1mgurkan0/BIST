<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\KapNewsRepository;
use App\Service\MarketCacheService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MarketController extends AbstractController
{
    #[Route('/ara', name: 'app_stock_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $symbol = strtoupper(trim((string) $request->query->get('q', '')));
        if ($symbol === '' || !preg_match('/^[A-Za-z0-9]{2,20}$/', $symbol)) {
            $this->addFlash('error', 'Geçerli bir BIST sembolü girin.');
            return $this->redirectToRoute('app-home');
        }
        return $this->redirectToRoute('app_stock_detail', ['symbol' => $symbol]);
    }

    #[Route('/', name: 'app-home', methods: ['GET'])]
    public function index(MarketCacheService $cacheService): Response
    {
        $cache = $cacheService->read();
        $marketStocks = $cache['data'] ?? [];
        $lastUpdate = $cache['updatedAt'] ?? 0;

        ksort($marketStocks);
        $bist30 = explode(',', 'AKBNK,ALARK,ARCLK,ASELS,ASTOR,BIMAS,EKGYO,ENKAI,EREGL,FROTO,GARAN,GUBRF,HEKTS,ISCTR,KCHOL,KONTR,KOZAL,KRDMD,ODAS,OYAKC,PETKM,PGSUS,SAHOL,SASA,SISE,TCELL,THYAO,TOASO,TUPRS,YKBNK');
        $bist50 = explode(',', 'AGHOL,AHGAZ,AKBNK,ALARK,ARCLK,ASELS,ASTOR,BERA,BIMAS,BRSAN,CANTE,CCOLA,CIMSA,CWENE,DOAS,DOHOL,EKGYO,ENJSA,ENKAI,EREGL,EUREN,FROTO,GARAN,GESAN,GUBRF,HALKB,HEKTS,ISCTR,ISMEN,KCHOL,KONTR,KOZAA,KOZAL,KRDMD,MGROS,ODAS,OYAKC,PETKM,PGSUS,SAHOL,SASA,SISE,SMRTG,SOKM,TAVHL,TCELL,THYAO,TOASO,TUPRS,YKBNK');
        
        $categorizedStocks = [
            'bist30' => [], 'bist50' => [], 'bist100' => [], 'all' => array_values($marketStocks),
        ];
        
        foreach ($marketStocks as $sym => $st) {
            if (in_array($sym, $bist30)) {
                $categorizedStocks['bist30'][] = $st; $categorizedStocks['bist100'][] = $st;
            } elseif (in_array($sym, $bist50)) {
                $categorizedStocks['bist50'][] = $st; $categorizedStocks['bist100'][] = $st;
            } else {
                $categorizedStocks['bist100'][] = $st;
            }
        }

        return $this->render('market/index.html.twig', [
            'marketStocks' => $categorizedStocks,
            'lastUpdate' => $lastUpdate > 0 ? date('H:i:s', $lastUpdate) : '-',
        ]);
    }

    #[Route('/hisse/{symbol}', name: 'app_stock_detail', requirements: ['symbol' => '[A-Za-z0-9]{2,20}'], methods: ['GET'])]
    public function detail(string $symbol, MarketCacheService $cacheService, KapNewsRepository $kapNewsRepository): Response
    {
        $symbol = strtoupper(trim($symbol));
        $cache = $cacheService->read();
        $marketStocks = $cache['data'] ?? [];
        $lastUpdate = $cache['updatedAt'] ?? 0;

        if (!isset($marketStocks[$symbol])) {
            $this->addFlash('error', 'Sembol cache\'de bulunamadi. Lutfen tarama listesini kontrol edin.');
            return $this->redirectToRoute('app-home');
        }
        
        $stockData = $marketStocks[$symbol];
        $newsList = $kapNewsRepository->findRecentForSymbol($symbol, new \DateTimeImmutable('-7 days'), 10);
        $symbolUpdatedAt = $stockData['updated_at'] ?? $lastUpdate;
        $fundamentals = $cacheService->readFrom('fundamentals_cache.json')['data'][$symbol] ?? [];
        
        return $this->render('market/hisse_detay.html.twig', [
            'stock' => $stockData,
            'symbol' => $symbol,
            'newsList' => $newsList,
            'lastUpdate' => $symbolUpdatedAt > 0 ? date('d.m.Y H:i:s', $symbolUpdatedAt) : '-',
            'fundamentals' => $fundamentals,
        ]);
    }

    #[Route('/api/market/poll', name: 'api_market_poll', methods: ['GET'])]
    public function pollMarket(Request $request, MarketCacheService $cacheService): JsonResponse
    {
        $symbol = strtoupper(trim((string) $request->query->get('symbol', '')));
        $cache = $cacheService->read();
        $marketStocks = $cache['data'] ?? [];
        $lastUpdate = $cache['updatedAt'] ?? 0;
        
        if ($symbol !== '') {
            if (isset($marketStocks[$symbol])) {
                $symbolUpdatedAt = $marketStocks[$symbol]['updated_at'] ?? $lastUpdate;
                return $this->json(['status' => 'ok', 'updatedAt' => $symbolUpdatedAt > 0 ? date('H:i:s', $symbolUpdatedAt) : '-', 'data' => $marketStocks[$symbol]]);
            }
            return $this->json(['status' => 'error', 'message' => 'Not found'], 404);
        }
        return $this->json(['status' => 'ok', 'updatedAt' => $lastUpdate > 0 ? date('H:i:s', $lastUpdate) : '-', 'data' => $marketStocks]);
    }
}
