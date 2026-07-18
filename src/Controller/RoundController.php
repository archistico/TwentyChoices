<?php

declare(strict_types=1);

namespace App\Controller;

use App\Game\Application\RoundQuery;
use App\Verification\Application\RoundVerificationPublisher;
use App\Verification\Application\RoundVerifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RoundController extends AbstractController
{
    #[Route(
        '/round/{publicCode}',
        name: 'app_round_show',
        requirements: ['publicCode' => 'R-[0-9]{8}-[A-F0-9]{12}'],
        methods: ['GET'],
    )]
    public function show(
        string $publicCode,
        RoundQuery $rounds,
        RoundVerificationPublisher $publisher,
        RoundVerifier $verifier,
    ): Response {
        $round = $rounds->byPublicCode($publicCode);
        if ($round === null) {
            throw $this->createNotFoundException('Round non trovato.');
        }

        if ($round->status === 'SETTLED' && !$round->verificationPublished()) {
            $publisher->publishLegacySettledByPublicCode($publicCode);
            $round = $rounds->byPublicCode($publicCode);
            if ($round === null) {
                throw $this->createNotFoundException('Round non trovato.');
            }
        }

        $verification = $verifier->verify(
            $round->publicCode,
            $round->questionSetHash,
            $round->commitment,
            $round->revealedWinningPath,
            $round->revealedSecretNonceHex,
        );

        return $this->render('round/show.html.twig', [
            'round' => $round,
            'verification' => $verification,
        ]);
    }

    #[Route('/storico', name: 'app_round_history', methods: ['GET'], priority: 10)]
    public function history(
        RoundQuery $rounds,
        RoundVerificationPublisher $publisher,
        RoundVerifier $verifier,
    ): Response {
        $recent = $rounds->recent(50);
        foreach ($recent as $round) {
            if ($round->status === 'SETTLED' && !$round->verificationPublished()) {
                $publisher->publishLegacySettledByPublicCode($round->publicCode);
            }
        }
        $recent = $rounds->recent(50);

        $rows = [];
        foreach ($recent as $round) {
            $verification = $verifier->verify(
                $round->publicCode,
                $round->questionSetHash,
                $round->commitment,
                $round->revealedWinningPath,
                $round->revealedSecretNonceHex,
            );
            $rows[] = ['round' => $round, 'verification' => $verification];
        }

        return $this->render('round/history.html.twig', ['rows' => $rows]);
    }
}
