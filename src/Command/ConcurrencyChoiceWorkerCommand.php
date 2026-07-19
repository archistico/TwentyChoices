<?php

declare(strict_types=1);

namespace App\Command;

use App\Game\Application\SubmitChoice;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'app:verification:concurrency-worker',
    description: 'Worker interno M1.9.8 usato per inviare una scelta sincronizzata da un processo PHP separato.',
)]
final class ConcurrencyChoiceWorkerCommand extends Command
{
    public function __construct(
        private readonly SubmitChoice $submitChoice,
        private readonly string $kernelEnvironment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('payload', null, InputOption::VALUE_REQUIRED, 'Percorso del payload JSON runtime M1.9.8.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->kernelEnvironment !== 'test') {
            $output->writeln('<error>Il worker M1.9.8 è disponibile esclusivamente in ambiente test.</error>');

            return Command::FAILURE;
        }

        $payloadPath = (string) $input->getOption('payload');
        if ($payloadPath === '' || !is_file($payloadPath)) {
            $output->writeln('<error>Payload M1.9.8 mancante.</error>');

            return Command::FAILURE;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode((string) file_get_contents($payloadPath), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new RuntimeException('Il payload M1.9.8 non è un oggetto JSON.');
            }

            $playCode = $this->requiredString($decoded, 'playCode');
            $sessionId = $this->requiredString($decoded, 'sessionId');
            $challengeToken = $this->requiredString($decoded, 'challengeToken');
            $selectedOption = $this->requiredString($decoded, 'selectedOption');
            $requestId = $this->requiredString($decoded, 'requestId');
            $readyPath = $this->requiredString($decoded, 'readyPath');
            $barrierPath = $this->requiredString($decoded, 'barrierPath');
            $resultPath = $this->requiredString($decoded, 'resultPath');

            if (file_put_contents($readyPath, 'ready', LOCK_EX) === false) {
                throw new RuntimeException('Impossibile segnalare la readiness del worker M1.9.8.');
            }

            $deadline = microtime(true) + 15.0;
            while (!is_file($barrierPath)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timeout in attesa della barriera M1.9.8.');
                }
                usleep(10_000);
            }

            try {
                $result = $this->submitChoice->submit(
                    $playCode,
                    $sessionId,
                    $challengeToken,
                    $selectedOption,
                    $requestId,
                    2_000,
                );
                $payload = [
                    'status' => 'success',
                    'playCode' => $playCode,
                    'acceptedStep' => $result->acceptedStep,
                    'completed' => $result->completed,
                    'outcome' => $result->outcome,
                    'nextRoundPublicCode' => $result->nextRoundPublicCode,
                ];
            } catch (Throwable $exception) {
                $payload = [
                    'status' => 'error',
                    'playCode' => $playCode,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ];
            }

            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (file_put_contents($resultPath, $json, LOCK_EX) === false) {
                throw new RuntimeException('Impossibile scrivere il risultato del worker M1.9.8.');
            }

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return Command::FAILURE;
        }
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Campo payload M1.9.8 non valido: %s.', $key));
        }

        return $value;
    }
}
