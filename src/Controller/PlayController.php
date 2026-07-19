<?php

declare(strict_types=1);

namespace App\Controller;

use App\Game\Application\OpenPlayStep;
use App\Game\Application\StartPlay;
use App\Game\Application\SubmitChoice;
use App\Game\Domain\Exception\ChoiceTooEarly;
use App\Game\Domain\Exception\DomainRuleViolation;
use App\Player\Application\PlayerSessionRegistry;
use App\Player\Http\PlayerCookieFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlayController extends AbstractController
{
    #[Route('/gioca/inizia', name: 'app_play_start', methods: ['POST'])]
    public function start(
        Request $request,
        PlayerSessionRegistry $sessions,
        StartPlay $startPlay,
    ): Response {
        if (!$this->isCsrfTokenValid('start_play', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF non valido.');
        }

        try {
            $identity = $sessions->requireExisting($request->cookies->get(PlayerCookieFactory::NAME));
            $play = $startPlay->start($identity->id);
            $response = $this->redirectToRoute('app_play_show', [
                'playPublicCode' => $play->publicCode,
            ]);
        } catch (DomainRuleViolation $exception) {
            $this->addFlash('error', $exception->getMessage());
            $response = $this->redirectToRoute('app_home');
        }

        return $response;
    }

    #[Route('/gioca/{playPublicCode}', name: 'app_play_show', methods: ['GET'])]
    public function show(
        string $playPublicCode,
        Request $request,
        PlayerSessionRegistry $sessions,
        OpenPlayStep $openStep,
    ): Response {
        try {
            $identity = $sessions->requireExisting($request->cookies->get(PlayerCookieFactory::NAME));
            $play = $openStep->open($playPublicCode, $identity->id);
        } catch (DomainRuleViolation $exception) {
            throw $this->createNotFoundException($exception->getMessage(), $exception);
        }

        return $this->render($play->completed ? 'play/completed.html.twig' : 'play/show.html.twig', [
            'play' => $play,
        ]);
    }

    #[Route('/gioca/{playPublicCode}/scelta', name: 'app_play_choice', methods: ['POST'])]
    public function choose(
        string $playPublicCode,
        Request $request,
        PlayerSessionRegistry $sessions,
        SubmitChoice $submitChoice,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid(
            'play_choice_'.$playPublicCode,
            (string) $request->request->get('_token'),
        )) {
            throw $this->createAccessDeniedException('Token CSRF non valido.');
        }

        try {
            $identity = $sessions->requireExisting($request->cookies->get(PlayerCookieFactory::NAME));
            $elapsed = $request->request->get('clientElapsedMilliseconds');
            $selectedOption = (string) $request->request->get('selectedOptionJs');
            if ($selectedOption === '') {
                $selectedOption = (string) $request->request->get('selectedOption');
            }
            $submitChoice->submit(
                $playPublicCode,
                $identity->id,
                (string) $request->request->get('challengeToken'),
                $selectedOption,
                (string) $request->request->get('requestId'),
                is_numeric($elapsed) ? (int) $elapsed : null,
            );
        } catch (ChoiceTooEarly $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (DomainRuleViolation $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_play_show', [
            'playPublicCode' => $playPublicCode,
        ]);
    }
}
