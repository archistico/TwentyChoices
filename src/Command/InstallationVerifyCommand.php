<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Bootstrap\InstallationVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:installation:verify',
    description: 'Verifica ambiente persistente, database, migrazioni, seed e separazione dev/test.',
)]
final class InstallationVerifyCommand extends Command
{
    public function __construct(private readonly InstallationVerifier $verifier)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->verifier->inspect();
        $rows = [];

        foreach ($report['checks'] as $check) {
            $rows[] = [strtoupper($check['status']), $check['name'], $check['value'], $check['detail']];
        }

        $io->table(['Stato', 'Controllo', 'Valore', 'Dettaglio'], $rows);

        if ($report['status'] === 'error') {
            $io->error('Verifica installazione M1.9.1 fallita.');

            return Command::FAILURE;
        }

        $io->success('Verifica installazione M1.9.1 completata senza errori.');

        return Command::SUCCESS;
    }
}
