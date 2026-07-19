<?php

declare(strict_types=1);

namespace App\Controller;

use App\Game\Application\RoundQuery;
use App\Player\Application\PlayerSessionRegistry;
use App\Player\Http\PlayerCookieFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(
        Request $request,
        RoundQuery $rounds,
        PlayerSessionRegistry $sessions,
        PlayerCookieFactory $cookies,
    ): Response {
        $identity = $sessions->resolve($request->cookies->get(PlayerCookieFactory::NAME));
        $response = $this->render('home/index.html.twig', [
            'activeRound' => $rounds->active(),
        ]);

        if ($identity->newlyCreated) {
            $response->headers->setCookie($cookies->create($identity->rawToken, $request->isSecure()));
        }

        return $response;
    }

    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json([
            'application' => 'TwentyChoices',
            'status' => 'ok',
            'mode' => 'free-technical-simulator',
        ]);
    }

    #[Route('/ready', name: 'app_ready', methods: ['GET'])]
    public function ready(Connection $connection): JsonResponse
    {
        try {
            $connection->fetchOne('SELECT id FROM game_round LIMIT 1');

            return $this->json([
                'application' => 'TwentyChoices',
                'status' => 'ready',
            ]);
        } catch (\Throwable) {
            return $this->json([
                'application' => 'TwentyChoices',
                'status' => 'not_ready',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }
}
