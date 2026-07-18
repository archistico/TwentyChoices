<?php

declare(strict_types=1);

namespace App\Command;

use App\Simulation\Application\RunSimulation;
use App\Simulation\Domain\SimulationProfile;
use App\Simulation\Domain\SimulationRequest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:simulation:run', description: 'Esegue una simulazione statistica isolata dai round reali.')]
final class RunSimulationCommand extends Command
{
    public function __construct(private readonly RunSimulation $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('plays', null, InputOption::VALUE_REQUIRED, 'Numero di giocate simulate (max 1.000.000)', '100000')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'UNIFORM, FIXED_A_BIAS o ALTERNATING_BIAS', 'UNIFORM')
            ->addOption('bias', null, InputOption::VALUE_REQUIRED, 'Probabilità percentuale base di A (50-95)', '70')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Seed riproducibile 0..2147483647', '20260718');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $plays = filter_var($input->getOption('plays'), FILTER_VALIDATE_INT);
            $bias = filter_var($input->getOption('bias'), FILTER_VALIDATE_FLOAT);
            $seed = filter_var($input->getOption('seed'), FILTER_VALIDATE_INT);
            if ($plays === false || $bias === false || $seed === false) {
                throw new \InvalidArgumentException('Parametri numerici non validi.');
            }

            $profile = SimulationProfile::from(strtoupper((string) $input->getOption('profile')));
            $code = $this->runner->run(new SimulationRequest($plays, $profile, (int) round($bias * 100), $seed));
            $io->success(sprintf('Simulazione %s completata e salvata.', $code));

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }
}
