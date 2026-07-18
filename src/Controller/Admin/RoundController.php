<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Game\Application\OpenRound;
use App\Game\Application\RoundQuery;
use App\Game\Domain\Exception\DomainRuleViolation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/round', name: 'admin_round_')]
final class RoundController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(RoundQuery $rounds): Response
    {
        return $this->render('admin/round/index.html.twig', [
            'activeRound' => $rounds->active(),
            'recentRounds' => $rounds->recent(),
        ]);
    }

    #[Route('/apri', name: 'open', methods: ['POST'])]
    public function open(Request $request, OpenRound $openRound): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('round_open', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF non valido.');
        }

        try {
            $round = $openRound->open();
            $this->addFlash(
                'success',
                sprintf('Round %s aperto con montepremio virtuale di 10.000,00 €.', $round->publicCode),
            );
        } catch (DomainRuleViolation $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_round_index');
    }
}
