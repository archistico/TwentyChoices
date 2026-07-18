<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\Admin\AdminPasswordHasher;
use App\Security\Admin\AdminRole;
use App\Security\Admin\AdminUserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:admin:create', description: 'Crea un account amministrativo TwentyChoices.')]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly AdminUserRepository $users,
        private readonly AdminPasswordHasher $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Username amministrativo')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'SUPER_ADMIN, OPERATOR o AUDITOR', AdminRole::SUPER_ADMIN->value)
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password; se omessa viene richiesta in modo nascosto');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $password = $input->getOption('password');
            if (!is_string($password) || $password === '') {
                $question = new Question('Password: ');
                $question->setHidden(true);
                $question->setHiddenFallback(false);
                $password = (string) $this->getHelper('question')->ask($input, $output, $question);
            }

            $role = AdminRole::from(strtoupper((string) $input->getOption('role')));
            $id = $this->users->create((string) $input->getArgument('username'), $this->hasher->hash($password), $role);
            $io->success(sprintf('Amministratore creato: %s (%s), id %s.', $input->getArgument('username'), $role->value, $id));

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }
}
