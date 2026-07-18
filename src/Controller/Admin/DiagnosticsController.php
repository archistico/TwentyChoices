<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\Application\SystemDiagnostics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DiagnosticsController extends AbstractController
{
    #[Route('/admin/diagnostica', name: 'admin_diagnostics_index', methods: ['GET'])]
    public function index(SystemDiagnostics $diagnostics): Response
    {
        return $this->render('admin/diagnostics/index.html.twig', [
            'report' => $diagnostics->inspect(),
        ]);
    }
}
