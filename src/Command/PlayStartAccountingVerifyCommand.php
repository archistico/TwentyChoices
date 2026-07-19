<?php

declare(strict_types=1);

namespace App\Command;

use App\Verification\Application\PlayStartAccountingGateVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:verification:play-start-accounting',
    description: 'Esegue il gate transazionale M1.9.4 su sessione anonima, avvio idempotente e contabilizzazione 100/80/20.',
)]
final class PlayStartAccountingVerifyCommand extends Command
{
    public function __construct(private readonly PlayStartAccountingGateVerifier $verifier)
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
            $io->error('Verifica M1.9.4 avvio giocata/contabilizzazione fallita. Tutte le mutazioni dello scenario sono state annullate.');

            return Command::FAILURE;
        }

        $io->success('Verifica M1.9.4 completata: sessione anonima, avvio idempotente e contabilizzazione 100/80/20 sono coerenti. Scenario rollbackato.');

        return Command::SUCCESS;
    }
}
