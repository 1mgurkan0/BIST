<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Cache\CacheInterface;

#[Route('/health')]
class HealthController extends AbstractController
{
    #[Route('/live', name: 'app_health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return $this->response(['status' => 'ok']);
    }

    #[Route('/ready', name: 'app_health_ready', methods: ['GET'])]
    public function ready(
        Connection $connection,
        CacheInterface $cache,
        LockFactory $lockFactory,
    ): JsonResponse {
        try {
            if ((int) $connection->fetchOne('SELECT 1') !== 1) {
                throw new \RuntimeException('Database probe failed.');
            }

            $cacheKey = 'health.ready.' . bin2hex(random_bytes(8));
            $item = $cache->getItem($cacheKey);
            $item->set('ok')->expiresAfter(30);
            if (!$cache->save($item) || $cache->getItem($cacheKey)->get() !== 'ok') {
                throw new \RuntimeException('Cache probe failed.');
            }
            $cache->deleteItem($cacheKey);

            $lock = $lockFactory->createLock('health_ready_probe', 5.0, false);
            if (!$lock->acquire()) {
                throw new \RuntimeException('Lock probe failed.');
            }
            $lock->release();

            return $this->response(['status' => 'ready']);
        } catch (\Throwable) {
            return $this->response(['status' => 'unavailable'], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /**
     * @param array<string, string> $payload
     */
    private function response(array $payload, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        $response = $this->json($payload, $status);
        $response->headers->set('Cache-Control', 'no-store, max-age=0');

        return $response;
    }
}
