<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Catalog\Application\ChoicePairCatalog;
use App\Catalog\Domain\Model\ChoicePair;
use App\Game\Domain\Exception\DomainRuleViolation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/scelte', name: 'admin_choice_pair_')]
final class ChoicePairController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(ChoicePairCatalog $catalog): Response
    {
        $pairs = $catalog->all();
        $activeRegular = count(array_filter(
            $pairs,
            static fn (ChoicePair $pair): bool => $pair->isActive() && !$pair->isSystem(),
        ));

        return $this->render('admin/choice_pair/index.html.twig', [
            'pairs' => $pairs,
            'activeRegular' => $activeRegular,
        ]);
    }

    #[Route('/nuova', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request, ChoicePairCatalog $catalog): Response
    {
        $data = $this->formData($request, $catalog->nextSortOrder());
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('choice_pair_create', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Token CSRF non valido.');
            }

            try {
                $catalog->create(
                    $data['code'],
                    $data['optionA'],
                    $data['optionB'],
                    $data['category'],
                    $data['sortOrder'],
                );
                $this->addFlash('success', 'Coppia creata correttamente.');

                return $this->redirectToRoute('admin_choice_pair_index');
            } catch (DomainRuleViolation $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->render('admin/choice_pair/form.html.twig', [
            'title' => 'Nuova coppia',
            'pair' => null,
            'data' => $data,
            'error' => $error,
            'csrfId' => 'choice_pair_create',
        ]);
    }

    #[Route('/{id}/modifica', name: 'edit', requirements: ['id' => '[0-9A-HJKMNP-TV-Z]{26}'], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, ChoicePairCatalog $catalog): Response
    {
        try {
            $pair = $catalog->get($id);
        } catch (DomainRuleViolation) {
            throw $this->createNotFoundException();
        }

        $data = $request->isMethod('POST') ? $this->formData($request) : [
            'code' => $pair->code(),
            'optionA' => $pair->optionAText(),
            'optionB' => $pair->optionBText(),
            'category' => $pair->category(),
            'sortOrder' => $pair->sortOrder(),
        ];
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('choice_pair_edit_'.$id, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Token CSRF non valido.');
            }

            try {
                $catalog->update(
                    $id,
                    $data['code'],
                    $data['optionA'],
                    $data['optionB'],
                    $data['category'],
                    $data['sortOrder'],
                );
                $this->addFlash('success', 'Coppia aggiornata correttamente.');

                return $this->redirectToRoute('admin_choice_pair_index');
            } catch (DomainRuleViolation $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->render('admin/choice_pair/form.html.twig', [
            'title' => 'Modifica coppia',
            'pair' => $pair,
            'data' => $data,
            'error' => $error,
            'csrfId' => 'choice_pair_edit_'.$id,
        ]);
    }

    #[Route('/{id}/stato', name: 'toggle', requirements: ['id' => '[0-9A-HJKMNP-TV-Z]{26}'], methods: ['POST'])]
    public function toggle(string $id, Request $request, ChoicePairCatalog $catalog): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('choice_pair_toggle_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF non valido.');
        }

        try {
            $pair = $catalog->toggle($id);
            $this->addFlash('success', $pair->isActive() ? 'Coppia attivata.' : 'Coppia disattivata.');
        } catch (DomainRuleViolation $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_choice_pair_index');
    }

    #[Route('/{id}/elimina', name: 'delete', requirements: ['id' => '[0-9A-HJKMNP-TV-Z]{26}'], methods: ['POST'])]
    public function delete(string $id, Request $request, ChoicePairCatalog $catalog): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('choice_pair_delete_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF non valido.');
        }

        try {
            $catalog->delete($id);
            $this->addFlash('success', 'Coppia eliminata.');
        } catch (DomainRuleViolation $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_choice_pair_index');
    }

    /** @return array{code: string, optionA: string, optionB: string, category: string, sortOrder: int} */
    private function formData(Request $request, ?int $defaultSortOrder = null): array
    {
        return [
            'code' => trim((string) $request->request->get('code', '')),
            'optionA' => trim((string) $request->request->get('option_a', '')),
            'optionB' => trim((string) $request->request->get('option_b', '')),
            'category' => trim((string) $request->request->get('category', '')),
            'sortOrder' => max(0, $request->request->getInt('sort_order', $defaultSortOrder ?? 0)),
        ];
    }
}
