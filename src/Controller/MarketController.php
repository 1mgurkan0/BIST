<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Stock;
use App\Entity\User;
use App\Repository\KapNewsRepository;
use App\Repository\StockRepository;
use App\Service\GeminiService;
use App\Service\TelegramService;
use App\Service\YahooFinanceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Cache\CacheInterface;

class MarketController extends AbstractController
{
    #[Route('/', name: 'app-home')]
    #[Route('/', name: 'market')]
    public function index(
        Request $request,
        StockRepository $repository,
        YahooFinanceService $yahoo,
        TelegramService $telegram,
        EntityManagerInterface $em,
        GeminiService $geminiService,
        KapNewsRepository $kapNewsRepository,
    ): Response {
        $currentUser = $this->getUser();

        if ($currentUser && !$currentUser->isVerified()) {
            return $this->redirectToRoute('app_verify_code');
        }

        $symbol = $request->query->get('symbol');
        $stock = null;
        $newsList = [];

        if ($symbol) {
            $stock = $repository->findRecent($symbol, 30);

            if (!$stock) {
                $dto = $yahoo->fetchOne($symbol);

                if ($dto) {
                    $stock = new Stock();
                    $stock->setSymbol($dto->symbol);
                    $stock->setPrice($dto->price);
                    $stock->setOpen($dto->open);
                    $stock->setHigh($dto->high);
                    $stock->setLow($dto->low);
                    $stock->setPreviousClose($dto->previousClose);
                    $stock->setVolume($dto->volume);
                    $stock->setCreatedAt(new \DateTime());

                    $em->persist($stock);
                    $em->flush();
                }
            }

            $threeDaysAgo = new \DateTimeImmutable('-7 days');

            $newsList = $kapNewsRepository->createQueryBuilder('n')
                ->where('n.stockCodes LIKE :symbol')
                ->andWhere('n.publishedAt >= :date')
                ->andWhere('n.isAnalyzed = true')
                ->setParameter('symbol', '%"' . $symbol . '"%')
                ->setParameter('date', $threeDaysAgo)
                ->orderBy('n.publishedAt', 'DESC')
                ->getQuery()
                ->getResult();
        }




        return $this->render('base.html.twig', [
            'stock' => $stock,
            'symbol' => $symbol,
            'newsList' => $newsList,
        ]);
    }

    #[Route('/api/bist30/live', name: 'api_bist30_live', methods: ['GET'])]
    public function liveBist30(CacheInterface $cache): JsonResponse
    {
        // Command'da belirlediğin Cache anahtarını kullanıyoruz
        $cacheKey = 'bist30.live.data';

        // Cache'den veriyi oku. Eğer cache boşsa (örn: cron henüz çalışmadıysa veya redis silindiyse)
        // Yahoo'ya gitmek yerine boş bir yapı dönerek frontend'in patlamasını önlüyoruz (Fallback).
        $data = $cache->get($cacheKey, function () {
            return [
                'fetchedAt' => null,
                'items'     => [],
                'status'    => 'waiting_for_command'
            ];
        });

        return $this->json($data);
    }
}