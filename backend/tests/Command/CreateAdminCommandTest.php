<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit/integration tests for the app:user:create-admin CLI command.
 */
final class CreateAdminCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserRepository $users;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = static::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $users = $container->get(UserRepository::class);
        \assert($users instanceof UserRepository);
        $this->users = $users;

        $this->em->getConnection()->executeStatement('DELETE FROM magic_link_tokens');
        $this->em->getConnection()->executeStatement('DELETE FROM invite_tokens');
        $this->em->getConnection()->executeStatement('DELETE FROM users');

        $application = new Application($kernel);
        $command = $application->find('app:user:create-admin');
        $this->tester = new CommandTester($command);
    }

    public function testCreateAdminCommandSuccess(): void
    {
        $exitCode = $this->tester->execute([
            'email' => 'cli-admin@yummy.test',
            'displayName' => 'CLI Admin',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Created Admin user', $this->tester->getDisplay());

        $admin = $this->users->findOneByEmail('cli-admin@yummy.test');
        self::assertNotNull($admin);
        self::assertTrue($admin->isAdmin());
    }

    public function testPromoteExistingMemberToAdmin(): void
    {
        $member = new User('member@yummy.test', 'Member', ['ROLE_USER']);
        $this->em->persist($member);
        $this->em->flush();

        $exitCode = $this->tester->execute([
            'email' => 'member@yummy.test',
            'displayName' => 'Member',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Promoted existing user', $this->tester->getDisplay());

        $promoted = $this->users->findOneByEmail('member@yummy.test');
        self::assertNotNull($promoted);
        self::assertTrue($promoted->isAdmin());
    }

    public function testAlreadyAdminReportsWarning(): void
    {
        $admin = new User('existing-admin@yummy.test', 'Admin', ['ROLE_ADMIN', 'ROLE_USER']);
        $this->em->persist($admin);
        $this->em->flush();

        $exitCode = $this->tester->execute([
            'email' => 'existing-admin@yummy.test',
            'displayName' => 'Admin',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('is already an Admin', $this->tester->getDisplay());
    }

    public function testInvalidEmailFails(): void
    {
        $exitCode = $this->tester->execute([
            'email' => 'not-an-email',
            'displayName' => 'Invalid',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('A valid email address is required', $this->tester->getDisplay());
    }

    public function testShortDisplayNameFails(): void
    {
        $exitCode = $this->tester->execute([
            'email' => 'valid@yummy.test',
            'displayName' => 'A', // Less than 2 characters
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Display name must be at least 2 characters', $this->tester->getDisplay());
    }
}
