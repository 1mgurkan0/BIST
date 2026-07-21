<?php

namespace App\Controller;

use App\Entity\PriceAlert;
use App\Entity\WatchlistItem;
use App\Repository\PriceAlertRepository;
use App\Repository\WatchlistItemRepository;
use App\Service\PriceSnapshotService;
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
        private readonly PriceSnapshotService $priceSnapshot,
    ) {}

    #[Route('', name: 'app_watchlist', methods: ['GET'])]
    public function index(WatchlistItemRepository $repository, PriceAlertRepository $alertRepository): Response
    {
        $items = $repository->findOrdered();
        $activeSymbols = array_map(
            fn(WatchlistItem $item) => $item->getSymbol(),
            array_filter($items, fn(WatchlistItem $item) => $item->isActive())
        );

        return $this->render('User/watchlist/index.html.twig', [
            'items' => $items,
            'alerts' => $alertRepository->findOrdered(),
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

    #[Route('/alerts/add', name: 'app_watchlist_alert_add', methods: ['POST'])]
    public function addAlert(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('watchlist_alert_add', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Gecersiz alarm istegi. Sayfayi yenileyip tekrar deneyin.');
            return $this->redirectToRoute('app_watchlist');
        }

        $symbol = strtoupper(trim((string) $request->request->get('symbol')));
        if (!preg_match('/^[A-Z0-9]{2,20}$/', $symbol)) {
            $this->addFlash('error', 'Alarm icin gecerli bir hisse sembolu girin.');
            return $this->redirectToRoute('app_watchlist');
        }

        $conditionType = (string) $request->request->get('conditionType');
        if (!in_array($conditionType, PriceAlert::TYPES, true)) {
            $this->addFlash('error', 'Gecerli bir alarm tipi secin.');
            return $this->redirectToRoute('app_watchlist');
        }

        $targetValue = str_replace(',', '.', trim((string) $request->request->get('targetValue')));
        if (!is_numeric($targetValue) || (float) $targetValue <= 0) {
            $this->addFlash('error', 'Alarm hedefi sifirdan buyuk olmali.');
            return $this->redirectToRoute('app_watchlist');
        }

        $note = $request->request->get('note');

        $alert = (new PriceAlert())
            ->setSymbol($symbol)
            ->setConditionType($conditionType)
            ->setTargetValue((float) $targetValue)
            ->setNote(is_string($note) ? $note : null);

        $em->persist($alert);
        $em->flush();

        $this->addFlash('success', $symbol . ' icin fiyat alarmi kuruldu.');

        return $this->redirectToRoute('app_watchlist');
    }

    #[Route('/alerts/toggle/{id}', name: 'app_watchlist_alert_toggle', methods: ['POST'])]
    public function toggleAlert(PriceAlert $alert, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('watchlist_alert_toggle_' . $alert->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Gecersiz alarm istegi. Sayfayi yenileyip tekrar deneyin.');
            return $this->redirectToRoute('app_watchlist');
        }

        if ($alert->isActive()) {
            $alert->setIsActive(false);
        } else {
            $alert->resetTrigger();
        }

        $em->flush();

        $this->addFlash('success', $alert->getSymbol() . ($alert->isActive() ? ' alarmi tekrar aktif.' : ' alarmi pasife alindi.'));

        return $this->redirectToRoute('app_watchlist');
    }

    #[Route('/alerts/reset/{id}', name: 'app_watchlist_alert_reset', methods: ['POST'])]
    public function resetAlert(PriceAlert $alert, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('watchlist_alert_reset_' . $alert->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Gecersiz alarm istegi. Sayfayi yenileyip tekrar deneyin.');
            return $this->redirectToRoute('app_watchlist');
        }

        $alert->resetTrigger();
        $em->flush();

        $this->addFlash('success', $alert->getSymbol() . ' alarmi tekrar kuruldu.');

        return $this->redirectToRoute('app_watchlist');
    }

    #[Route('/alerts/delete/{id}', name: 'app_watchlist_alert_delete', methods: ['POST'])]
    public function deleteAlert(PriceAlert $alert, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('watchlist_alert_delete_' . $alert->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Gecersiz alarm istegi. Sayfayi yenileyip tekrar deneyin.');
            return $this->redirectToRoute('app_watchlist');
        }

        $symbol = $alert->getSymbol();
        $em->remove($alert);
        $em->flush();

        $this->addFlash('success', $symbol . ' alarmi silindi.');

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
        return $this->priceSnapshot->payloadForSymbols($symbols);
    }
}
