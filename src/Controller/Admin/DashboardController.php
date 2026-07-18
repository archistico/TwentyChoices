<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\Admin\AdminAuthentication;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function index(AdminAuthentication $authentication): Response
    {
        return $this->render('admin/dashboard/index.html.twig', [
            'admin' => $authentication->current(),
        ]);
    }
}
