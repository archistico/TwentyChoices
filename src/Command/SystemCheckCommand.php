<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\Application\SystemDiagnostics;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:system:check',
    description: 'Verifica integrità SQLite, configurazione runtime e catena audit.',
)]
final class SystemCheckCommand extends Command
{
    public function __construct(private readonly SystemDiagnostics $diagnostics)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->diagnostics->inspect();

        $rows = [];
        foreach ($report['checks'] as $check) {
            $rows[] = [strtoupper($check['status']), $check['name'], $check['value'], $check['detail']];
        }
        $io->table(['Stato', 'Controllo', 'Valore', 'Dettaglio'], $rows);

        if ($report['status'] === 'error') {
            $io->error('Sono presenti controlli critici non validi.');

            return Command::FAILURE;
        }

        if ($report['status'] === 'warning') {
            $io->warning('Controlli critici validi, con almeno un warning operativo.');

            return Command::SUCCESS;
        }

        $io->success('Diagnostica TwentyChoices completata senza errori.');

        return Command::SUCCESS;
    }
}
