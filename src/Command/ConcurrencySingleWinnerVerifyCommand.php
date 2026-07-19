<?php

declare(strict_types=1);

namespace App\Command;

use App\Verification\Application\ConcurrencySingleWinnerGateVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:verification:concurrency-single-winner',
    description: 'Esegue il gate multiprocesso M1.9.8 su concorrenza, single-winner e richieste stale.',
)]
final class ConcurrencySingleWinnerVerifyCommand extends Command
{
    public function __construct(private readonly ConcurrencySingleWinnerGateVerifier $verifier)
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
            $io->error('Verifica M1.9.8 concurrency/single-winner fallita. Il database di test è stato ripristinato dalla snapshot pre-gate.');

            return Command::FAILURE;
        }

        $io->success('Verifica M1.9.8 completata: tre race multiprocesso, un solo vincitore/payout/nuovo round, loser gestito senza errore infrastrutturale, richiesta stale respinta e database di test ripristinato.');

        return Command::SUCCESS;
    }
}
