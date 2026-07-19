<?php

declare(strict_types=1);

namespace App\Command;

use App\Verification\Application\CryptographicCommitmentGateVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:verification:cryptographic-commitment',
    description: 'Esegue il gate transazionale M1.9.3 sul commit-reveal e sulla segretezza del round ACTIVE.',
)]
final class CryptographicCommitmentVerifyCommand extends Command
{
    public function __construct(private readonly CryptographicCommitmentGateVerifier $verifier)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->verifier->verify();
        $rows = [];

        foreach ($report['checks'] as $check) {
            $rows[] = [strtoupper($check['status']), $check['name'], $check['value'], $check['detail']];
        }

        $io->table(['Stato', 'Controllo', 'Valore', 'Dettaglio'], $rows);

        if ($report['status'] === 'error') {
            $io->error('Verifica M1.9.3 commitment crittografico fallita. Tutte le mutazioni dello scenario sono state annullate.');

            return Command::FAILURE;
        }

        $io->success('Verifica M1.9.3 completata: commit-reveal, tamper detection, cifratura contestuale e non-disclosure ACTIVE sono coerenti. Scenario rollbackato.');

        return Command::SUCCESS;
    }
}
