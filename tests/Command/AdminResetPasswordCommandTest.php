<?php

namespace App\Tests\Command;

use App\Command\AdminResetPasswordCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AdminResetPasswordCommandTest extends TestCase
{
    public function testItCreatesAndPersistsANewVerifiedAdmin(): void
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static fn (User $user): bool => $user->getEmail() === 'admin@example.test'));
        $entityManager->expects(self::once())->method('flush');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())
            ->method('hashPassword')
            ->with(self::isInstanceOf(User::class), 'strong-password')
            ->willReturn('hashed-password');

        $tester = new CommandTester(new AdminResetPasswordCommand($repository, $entityManager, $hasher));
        $status = $tester->execute([
            'email' => '  ADMIN@EXAMPLE.TEST ',
            '--password' => 'strong-password',
            '--first-name' => 'Test',
            '--last-name' => 'Admin',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Yeni admin kullanici hazir', $tester->getDisplay());
    }

    public function testItUpdatesAnExistingUserWithoutPersistingAgain(): void
    {
        $user = (new User())
            ->setEmail('admin@example.test')
            ->setFirstName('Existing')
            ->setLastName('User')
            ->setRoles([])
            ->setIsVerified(false)
            ->setPassword('old-hash');
        $user->generateResetCode();

        $repository = $this->createStub(UserRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('new-hash');

        $tester = new CommandTester(new AdminResetPasswordCommand($repository, $entityManager, $hasher));
        $status = $tester->execute([
            'email' => 'admin@example.test',
            '--password' => 'another-strong-password',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertTrue($user->isVerified());
        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertSame('new-hash', $user->getPassword());
        self::assertNull($user->getResetCode());
        self::assertStringContainsString('Mevcut admin kullanici hazir', $tester->getDisplay());
    }
}
