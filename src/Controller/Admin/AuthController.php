<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\Admin\AdminAuthentication;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController extends AbstractController
{
    #[Route('/admin/login', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(Request $request, AdminAuthentication $authentication): Response
    {
        if ($authentication->current() !== null) {
            return $this->redirectToRoute('admin_dashboard');
        }

        $error = null;
        $username = '';
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_login', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Token CSRF non valido.');
            }

            $username = trim((string) $request->request->get('username'));
            $password = (string) $request->request->get('password');
            if ($authentication->login($username, $password, $request->attributes->getString('_twenty_request_id'))) {
                $target = (string) $request->getSession()->remove('twenty_admin_target');
                if ($target !== '' && str_starts_with($target, '/admin') && !str_starts_with($target, '//')) {
                    return new RedirectResponse($target);
                }

                return $this->redirectToRoute('admin_dashboard');
            }

            $error = 'Credenziali non valide.';
        }

        return $this->render('admin/auth/login.html.twig', [
            'error' => $error,
            'username' => $username,
        ]);
    }

    #[Route('/admin/logout', name: 'admin_logout', methods: ['POST'])]
    public function logout(Request $request, AdminAuthentication $authentication): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_logout', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF non valido.');
        }

        $authentication->logout($request->attributes->getString('_twenty_request_id'));

        return $this->redirectToRoute('admin_login');
    }
}
