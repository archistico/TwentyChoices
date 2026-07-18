<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Simulation\Application\RunSimulation;
use App\Simulation\Application\SimulationQuery;
use App\Simulation\Domain\SimulationProfile;
use App\Simulation\Domain\SimulationRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/simulazioni', name: 'admin_simulation_')]
final class SimulationController extends AbstractController
{
    private const WEB_MAX_PLAYS = 250_000;

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(SimulationQuery $simulations): Response
    {
        return $this->render('admin/simulation/index.html.twig', [
            'profiles' => SimulationProfile::cases(),
            'recentRuns' => $simulations->recent(),
            'gameMetrics' => $simulations->realGameMetrics(),
            'webMaxPlays' => self::WEB_MAX_PLAYS,
        ]);
    }

    #[Route('/esegui', name: 'run', methods: ['POST'])]
    public function run(Request $request, RunSimulation $runner): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('simulation_run', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF non valido.');
        }

        try {
            $plays = filter_var($request->request->get('plays'), FILTER_VALIDATE_INT);
            $biasPercent = filter_var($request->request->get('bias_percent'), FILTER_VALIDATE_FLOAT);
            $seed = filter_var($request->request->get('seed'), FILTER_VALIDATE_INT);
            $profile = SimulationProfile::from((string) $request->request->get('profile'));

            if ($plays === false || $plays < 1 || $plays > self::WEB_MAX_PLAYS) {
                throw new \InvalidArgumentException(sprintf('Dal browser sono consentite da 1 a %s giocate.', number_format(self::WEB_MAX_PLAYS, 0, ',', '.')));
            }
            if ($biasPercent === false || $biasPercent < 50 || $biasPercent > 95) {
                throw new \InvalidArgumentException('La preferenza A deve essere compresa tra 50% e 95%.');
            }
            if ($seed === false || $seed < 0 || $seed > 2_147_483_647) {
                throw new \InvalidArgumentException('Il seed non è valido.');
            }

            $publicCode = $runner->run(new SimulationRequest(
                $plays,
                $profile,
                (int) round($biasPercent * 100),
                $seed,
            ));

            $this->addFlash('success', sprintf('Simulazione %s completata senza modificare round o ledger reali.', $publicCode));

            return $this->redirectToRoute('admin_simulation_show', ['publicCode' => $publicCode]);
        } catch (\ValueError|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('admin_simulation_index');
        }
    }

    #[Route('/{publicCode}', name: 'show', requirements: ['publicCode' => 'S-[0-9]{8}-[A-F0-9]{12}'], methods: ['GET'])]
    public function show(string $publicCode, SimulationQuery $simulations): Response
    {
        $run = $simulations->byPublicCode($publicCode);
        if ($run === null) {
            throw $this->createNotFoundException('Simulazione non trovata.');
        }

        return $this->render('admin/simulation/show.html.twig', ['run' => $run]);
    }

    #[Route('/{publicCode}/csv', name: 'csv', requirements: ['publicCode' => 'S-[0-9]{8}-[A-F0-9]{12}'], methods: ['GET'])]
    public function csv(string $publicCode, SimulationQuery $simulations): Response
    {
        $run = $simulations->byPublicCode($publicCode);
        if ($run === null) {
            throw $this->createNotFoundException('Simulazione non trovata.');
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Impossibile creare il CSV temporaneo.');
        }

        fputcsv($stream, ['TwentyChoices M1.6 - simulazione', $run->publicCode], ';', '"', '');
        fputcsv($stream, ['profilo', $run->profile], ';', '"', '');
        fputcsv($stream, ['giocate', $run->plays], ';', '"', '');
        fputcsv($stream, ['seed', $run->seed], ';', '"', '');
        fputcsv($stream, ['percorsi_distinti', $run->uniquePaths], ';', '"', '');
        fputcsv($stream, ['giocate_duplicate', $run->duplicatePlays], ';', '"', '');
        fputcsv($stream, ['copertura_ppm', $run->coveragePpm], ';', '"', '');
        fputcsv($stream, ['entropia_millibit', $run->shannonEntropyMillibits], ';', '"', '');
        fputcsv($stream, ['percorsi_effettivi_osservati', $run->effectivePathCount], ';', '"', '');
        fputcsv($stream, [], ';', '"', '');
        fputcsv($stream, ['step', 'probabilita_A_bp', 'scelte_A', 'scelte_B'], ';', '"', '');
        foreach ($run->choiceStats as $stat) {
            fputcsv($stream, [$stat['step'], $stat['probabilityABasisPoints'], $stat['optionACount'], $stat['optionBCount']], ';', '"', '');
        }
        fputcsv($stream, [], ';', '"', '');
        fputcsv($stream, ['rank', 'percorso', 'occorrenze'], ';', '"', '');
        foreach ($run->topPaths as $path) {
            fputcsv($stream, [$path['rank'], $path['pathBits'], $path['hitCount']], ';', '"', '');
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return new Response((string) $content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s.csv"', $run->publicCode),
        ]);
    }
}
