<?php

declare(strict_types=1);

namespace App\Command;

use App\Verification\Application\CatalogRoundGateVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:verification:catalog-round',
    description: 'Esegue il gate transazionale M1.9.2 su catalogo, snapshot e apertura round.',
)]
final class CatalogRoundVerifyCommand extends Command
{
    public function __construct(private readonly CatalogRoundGateVerifier $verifier)
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
            $io->error('Verifica M1.9.2 catalogo/round fallita. Tutte le modifiche dello scenario sono state annullate.');

            return Command::FAILURE;
        }

        $io->success('Verifica M1.9.2 completata: scenario transazionale interamente annullato dopo i controlli.');

        return Command::SUCCESS;
    }
}
