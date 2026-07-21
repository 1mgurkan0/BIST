<?php

declare(strict_types=1);

namespace App\Controller;

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
    #[Route('/', name: 'app-home')]
    #[Route('/', name: 'market')]
    public function index(
        Request $request,
        StockRepository $repository,
        YahooFinanceService $yahoo,
        EntityManagerInterface $em,
        KapNewsRepository $kapNewsRepository,
    ): Response {
        $currentUser = $this->getUser();

        if ($currentUser && !$currentUser->isVerified()) {
            return $this->redirectToRoute('app_verify_code');
        }

        $symbol = strtoupper(trim((string) $request->query->get('symbol', '')));
        $stock = null;
        $newsList = [];

        if ($symbol !== '') {
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
}
