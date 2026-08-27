<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\MarketDataDto;
use App\Entity\Stock;
use App\Repository\KapNewsRepository;
use App\Repository\StockRepository;
use App\Service\YahooFinanceService;
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
    ): Response {
        $symbol = strtoupper(trim((string) $request->query->get('symbol', '')));
        $stock = null;
        $newsList = [];
        $marketDataStatus = null;

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
        }

        return $this->render('market/index.html.twig', [
            'stock' => $stock,
            'symbol' => $symbol,
            'newsList' => $newsList,
            'marketDataStatus' => $marketDataStatus,
        ]);
    }
}
