<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateAdminCommandTest extends KernelTestCase
{
    public function testCreateAdminCommand(): void
    {
        $kernel = self::bootKernel();
        $container = static::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->getConnection()->executeStatement('DELETE FROM magic_link_tokens');
        $em->getConnection()->executeStatement('DELETE FROM invite_tokens');
        $em->getConnection()->executeStatement('DELETE FROM users');

        $application = new Application($kernel);
        $command = $application->find('app:user:create-admin');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'email' => 'cli-admin@yummy.test',
            'displayName' => 'CLI Admin',
        ]);

        self::assertSame(0, $exitCode);

        $users = $container->get(UserRepository::class);
        \assert($users instanceof UserRepository);
        $admin = $users->findOneByEmail('cli-admin@yummy.test');
        self::assertNotNull($admin);
        self::assertTrue($admin->isAdmin());
    }
}
