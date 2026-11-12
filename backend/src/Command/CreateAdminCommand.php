<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI command to provision or promote an initial Admin user.
 *
 * This command provides an explicit, out-of-band mechanism for instance owners
 * to create the first administrative account without requiring an active browser session.
 */
#[AsCommand(
    name: 'app:user:create-admin',
    description: 'Create or promote an Admin user for magic-link login.',
)]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Admin email address')
            ->addArgument('displayName', InputArgument::REQUIRED, 'Admin display name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $displayName = trim((string) $input->getArgument('displayName'));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('A valid email address is required.');

            return Command::FAILURE;
        }

        if (mb_strlen($displayName) < 2) {
            $io->error('Display name must be at least 2 characters.');

            return Command::FAILURE;
        }

        $existing = $this->users->findOneByEmail($email);
        if ($existing !== null) {
            if ($existing->isAdmin()) {
                $io->warning(sprintf('User "%s" is already an Admin.', $email));

                return Command::SUCCESS;
            }

            // Promote existing member to Admin
            $existing->setRoles([...$existing->getRoles(), 'ROLE_ADMIN']);
            $this->em->flush();
            $io->success(sprintf('Promoted existing user "%s" to Admin.', $email));

            return Command::SUCCESS;
        }

        // Provision net-new Admin account
        $admin = new User($email, $displayName, ['ROLE_ADMIN', 'ROLE_USER']);
        $this->em->persist($admin);
        $this->em->flush();

        $io->success(sprintf('Created Admin user "%s" (%s).', $displayName, $email));

        return Command::SUCCESS;
    }
}
