<?php

declare(strict_types=1);

namespace App\Command;

use App\Verification\Application\StepTimerAntiReplayGateVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:verification:step-timer-anti-replay',
    description: 'Esegue il gate transazionale M1.9.5 su step, timer esatto, token rotation e anti-replay.',
)]
final class StepTimerAntiReplayVerifyCommand extends Command
{
    public function __construct(private readonly StepTimerAntiReplayGateVerifier $verifier)
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
            $io->error('Verifica M1.9.5 step/timer/anti-replay fallita. Tutte le mutazioni dello scenario sono state annullate.');

            return Command::FAILURE;
        }

        $io->success('Verifica M1.9.5 completata: timer esatto, refresh, token rotation, replay e ownership non permettono al client di controllare la macchina a stati. Scenario rollbackato.');

        return Command::SUCCESS;
    }
}
