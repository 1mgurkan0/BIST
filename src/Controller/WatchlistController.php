<?php

namespace App\Controller;

use App\Entity\WatchlistItem;
use App\Repository\WatchlistItemRepository;
use App\Service\YahooFinanceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/watchlist')]
class WatchlistController extends AbstractController
{
    public function __construct(
        private readonly YahooFinanceService $yahooFinance,
    ) {}

    #[Route('', name: 'app_watchlist', methods: ['GET'])]
    public function index(WatchlistItemRepository $repository): Response
    {
        $items = $repository->findOrdered();
        $activeSymbols = array_map(
            fn(WatchlistItem $item) => $item->getSymbol(),
            array_filter($items, fn(WatchlistItem $item) => $item->isActive())
        );

        return $this->render('User/watchlist/index.html.twig', [
            'items' => $items,
            'marketData' => $this->marketDataPayload($activeSymbols),
        ]);
    }

    #[Route('/add', name: 'app_watchlist_add', methods: ['POST'])]
    public function add(Request $request, WatchlistItemRepository $repository, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('watchlist_add', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Gecersiz istek. Sayfayi yenileyip tekrar deneyin.');
            return $this->redirectToRoute('app_watchlist');
        }

        $symbol = strtoupper(trim((string) $request->request->get('symbol')));
        if (!preg_match('/^[A-Z0-9]{2,20}$/', $symbol)) {
            $this->addFlash('error', 'Gecerli bir hisse sembolu girin.');
            return $this->redirectToRoute('app_watchlist');
        }

        $note = $request->request->get('note');
        $item = $repository->findOneBySymbol($symbol);

        if ($item instanceof WatchlistItem) {
            $item->setIsActive(true);
            if (is_string($note)) {
                $item->setNote($note);
            }
            $this->addFlash('success', $symbol . ' zaten takip listesindeydi, aktif hale getirildi.');
        } else {
            $item = (new WatchlistItem())
                ->setSymbol($symbol)
                ->setNote(is_string($note) ? $note : null);
            $em->persist($item);
            $this->addFlash('success', $symbol . ' takip listesine eklendi.');
        }

        $em->flush();

        return $this->redirectToRoute('app_watchlist');
    }

    #[Route('/toggle/{id}', name: 'app_watchlist_toggle', methods: ['POST'])]
    public function toggle(WatchlistItem $item, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('watchlist_toggle_' . $item->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Gecersiz istek. Sayfayi yenileyip tekrar deneyin.');
            return $this->redirectToRoute('app_watchlist');
        }

        $item->setIsActive(!$item->isActive());
        $em->flush();

        $this->addFlash('success', $item->getSymbol() . ($item->isActive() ? ' tekrar aktif takipte.' : ' pasif takip listesine alindi.'));

        return $this->redirectToRoute('app_watchlist');
    }

    #[Route('/delete/{id}', name: 'app_watchlist_delete', methods: ['POST'])]
    public function delete(WatchlistItem $item, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('watchlist_delete_' . $item->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Gecersiz istek. Sayfayi yenileyip tekrar deneyin.');
            return $this->redirectToRoute('app_watchlist');
        }

        $symbol = $item->getSymbol();
        $em->remove($item);
        $em->flush();

        $this->addFlash('success', $symbol . ' takip listesinden silindi.');

        return $this->redirectToRoute('app_watchlist');
    }

    #[Route('/api/live', name: 'api_watchlist_live', methods: ['GET'])]
    public function live(WatchlistItemRepository $repository): JsonResponse
    {
        $symbols = array_map(
            fn(WatchlistItem $item) => $item->getSymbol(),
            $repository->findActive()
        );

        return $this->json($this->marketDataPayload($symbols));
    }

    /**
     * @param string[] $symbols
     * @return array<string, mixed>
     */
    private function marketDataPayload(array $symbols): array
    {
        $symbols = array_values(array_unique(array_filter(array_map('strtoupper', $symbols))));
        $marketDataMap = $this->yahooFinance->fetchBatchWithStatus($symbols);

        $items = [];
        foreach ($symbols as $symbol) {
            $quoteStatus = $marketDataMap[$symbol] ?? null;
            $data = $quoteStatus['data'] ?? null;
            $lastSuccessful = $quoteStatus['lastSuccessful'] ?? null;
            $dailyChange = $data ? $data->price - $data->previousClose : null;

            $items[$symbol] = [
                'symbol' => $symbol,
                'price' => $data?->price,
                'previousClose' => $data?->previousClose,
                'dailyChange' => $dailyChange,
                'dailyChangePercent' => $data?->changePercent(),
                'volume' => $data?->volume,
                'status' => $quoteStatus['status'] ?? ($data ? 'ok' : 'missing_price'),
                'source' => $quoteStatus['source'] ?? null,
                'httpStatus' => $quoteStatus['httpStatus'] ?? null,
                'statusMessage' => $quoteStatus['message'] ?? null,
                'isStale' => (bool) ($quoteStatus['isStale'] ?? false),
                'lastSuccessfulPrice' => $lastSuccessful?->price,
                'lastSuccessfulAt' => $lastSuccessful?->fetchedAt?->format(\DateTimeInterface::ATOM),
            ];
        }

        return [
            'fetchedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'items' => $items,
        ];
    }
}
