<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\Admin\AdminAuthentication;
use App\Security\Admin\AdminPasswordHasher;
use App\Security\Admin\AdminRole;
use App\Security\Admin\AdminUserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/utenti', name: 'admin_user_')]
final class AdminUserController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(AdminUserRepository $users): Response
    {
        return $this->render('admin/user/index.html.twig', ['users' => $users->all()]);
    }

    #[Route('/nuovo', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request, AdminUserRepository $users, AdminPasswordHasher $hasher): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_user_create', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Token CSRF non valido.');
            }

            try {
                $username = (string) $request->request->get('username');
                $role = AdminRole::from((string) $request->request->get('role'));
                $password = (string) $request->request->get('password');
                $users->create($username, $hasher->hash($password), $role);
                $this->addFlash('success', 'Amministratore creato correttamente.');

                return $this->redirectToRoute('admin_user_index');
            } catch (UniqueConstraintViolationException) {
                $error = 'Esiste già un amministratore con questo username.';
            } catch (\ValueError|\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->render('admin/user/form.html.twig', [
            'mode' => 'create',
            'roles' => AdminRole::cases(),
            'user' => null,
            'error' => $error,
        ]);
    }

    #[Route('/{id}/modifica', name: 'edit', requirements: ['id' => '[0-9A-HJKMNP-TV-Z]{26}'], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, AdminUserRepository $users): Response
    {
        $user = $users->findById($id);
        if ($user === null) {
            throw $this->createNotFoundException('Amministratore non trovato.');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_user_edit_'.$id, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Token CSRF non valido.');
            }

            try {
                $users->updateRole($id, AdminRole::from((string) $request->request->get('role')));
                $this->addFlash('success', 'Ruolo aggiornato.');

                return $this->redirectToRoute('admin_user_index');
            } catch (\Throwable $exception) {
                $error = str_contains($exception->getMessage(), 'last active SUPER_ADMIN')
                    ? 'Non puoi rimuovere il ruolo all ultimo SUPER_ADMIN attivo.'
                    : 'Impossibile aggiornare il ruolo.';
            }
        }

        return $this->render('admin/user/form.html.twig', [
            'mode' => 'edit',
            'roles' => AdminRole::cases(),
            'user' => $user,
            'error' => $error,
        ]);
    }

    #[Route('/{id}/password', name: 'password', requirements: ['id' => '[0-9A-HJKMNP-TV-Z]{26}'], methods: ['POST'])]
    public function password(string $id, Request $request, AdminUserRepository $users, AdminPasswordHasher $hasher): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_user_password_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF non valido.');
        }

        try {
            $users->updatePassword($id, $hasher->hash((string) $request->request->get('password')));
            $this->addFlash('success', 'Password aggiornata.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_user_edit', ['id' => $id]);
    }

    #[Route('/{id}/stato', name: 'toggle', requirements: ['id' => '[0-9A-HJKMNP-TV-Z]{26}'], methods: ['POST'])]
    public function toggle(string $id, Request $request, AdminUserRepository $users, AdminAuthentication $authentication): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_user_toggle_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF non valido.');
        }

        $user = $users->findById($id);
        if ($user === null) {
            throw $this->createNotFoundException('Amministratore non trovato.');
        }
        $current = $authentication->current();
        if ($current !== null && $current->id === $id && (int) $user['is_active'] === 1) {
            $this->addFlash('error', 'Non puoi disattivare la sessione amministrativa che stai usando.');

            return $this->redirectToRoute('admin_user_index');
        }

        try {
            $users->setActive($id, (int) $user['is_active'] !== 1);
            $this->addFlash('success', 'Stato amministratore aggiornato.');
        } catch (\Throwable $exception) {
            $this->addFlash('error', str_contains($exception->getMessage(), 'last active SUPER_ADMIN')
                ? 'Non puoi disattivare l ultimo SUPER_ADMIN attivo.'
                : 'Impossibile aggiornare lo stato amministratore.');
        }

        return $this->redirectToRoute('admin_user_index');
    }
}
