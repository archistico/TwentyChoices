<?php

declare(strict_types=1);

namespace App\Controller;

use App\Verification\Application\ReceiptQuery;
use App\Verification\Application\RoundVerificationPublisher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VerificationController extends AbstractController
{
    #[Route(
        '/verifica/{verificationCode}',
        name: 'app_verification_receipt',
        requirements: ['verificationCode' => 'V-[A-F0-9]{24}'],
        methods: ['GET'],
    )]
    public function receipt(
        string $verificationCode,
        ReceiptQuery $receipts,
        RoundVerificationPublisher $publisher,
    ): Response {
        $receipt = $receipts->byVerificationCode($verificationCode);
        if ($receipt === null) {
            throw $this->createNotFoundException('Codice di verifica non trovato.');
        }

        if ($receipt->roundStatus === 'SETTLED' && !$receipt->roundVerificationAvailable) {
            $publisher->publishLegacySettledByPublicCode($receipt->roundPublicCode);
            $receipt = $receipts->byVerificationCode($verificationCode);
            if ($receipt === null) {
                throw $this->createNotFoundException('Codice di verifica non trovato.');
            }
        }

        return $this->render('verification/receipt.html.twig', [
            'receipt' => $receipt,
        ]);
    }
}
