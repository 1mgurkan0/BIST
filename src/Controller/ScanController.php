<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MarketCacheService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ScanController extends AbstractController
{
    #[Route('/bist-tarama', name: 'app_bist_scan')]
    public function scan(MarketCacheService $cacheService): Response
    {
        $cache = $cacheService->read();
        $marketStocks = $cache['data'] ?? [];
        $lastUpdate = $cache['updatedAt'] ?? 0;
        
        return $this->render('market/scan.html.twig', [
            'marketStocks' => array_values($marketStocks),
            'lastUpdate' => $lastUpdate > 0 ? date('d.m.Y H:i:s', $lastUpdate) : '-',
        ]);
    }
}
