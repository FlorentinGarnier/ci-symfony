<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sonde utilisée par le script de déploiement et les tests de fumée.
 * Expose le commit pour vérifier que la version servie est bien celle déployée.
 */
final class HealthController
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire(env: 'default::APP_VERSION')]
        private readonly ?string $version,
        #[Autowire(env: 'default::APP_COMMIT')]
        private readonly ?string $commit,
    ) {
    }

    #[Route('/healthz', name: 'app_healthz', methods: ['GET', 'HEAD'])]
    public function __invoke(): JsonResponse
    {
        try {
            $this->connection->executeQuery('SELECT 1');
            $database = 'ok';
        } catch (\Throwable) {
            $database = 'unavailable';
        }

        $response = new JsonResponse(
            [
                'status' => 'ok' === $database ? 'ok' : 'degraded',
                'database' => $database,
                'version' => $this->version ?? 'dev',
                'commit' => $this->commit ?? 'unknown',
            ],
            'ok' === $database ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
