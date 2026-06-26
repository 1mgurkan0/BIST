<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:admin:reset-password',
    description: 'Kisisel sistem icin admin kullanici olusturur veya sifresini sifirlar.',
)]
class AdminResetPasswordCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Admin kullanicinin e-posta adresi.')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Yeni sifre. Bos birakilirsa interaktif olarak sorulur.')
            ->addOption('first-name', null, InputOption::VALUE_REQUIRED, 'Yeni kullanici olusursa ad alani.', 'Admin')
            ->addOption('last-name', null, InputOption::VALUE_REQUIRED, 'Yeni kullanici olusursa soyad alani.', 'User');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = strtolower(trim((string) $input->getArgument('email')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Gecerli bir e-posta adresi gir.');
            return Command::INVALID;
        }

        $plainPassword = $this->resolvePassword($input, $io);
        if ($plainPassword === null) {
            return Command::INVALID;
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);
        $created = false;

        if (!$user instanceof User) {
            $user = (new User())
                ->setEmail($email)
                ->setFirstName((string) $input->getOption('first-name'))
                ->setLastName((string) $input->getOption('last-name'));
            $created = true;
            $this->em->persist($user);
        }

        $user
            ->setRoles(['ROLE_ADMIN'])
            ->setIsVerified(true);
        $user->clearResetCode();
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        $this->em->flush();

        $io->success(sprintf(
            '%s admin kullanici hazir: %s',
            $created ? 'Yeni' : 'Mevcut',
            $user->getEmail()
        ));

        return Command::SUCCESS;
    }

    private function resolvePassword(InputInterface $input, SymfonyStyle $io): ?string
    {
        $password = $input->getOption('password');

        if ($password === null) {
            if (!$input->isInteractive()) {
                $io->error('Non-interactive modda --password opsiyonu zorunlu.');
                return null;
            }

            $password = $io->askHidden('Yeni admin sifresi', function (?string $value): string {
                return $this->validatePassword($value);
            });
        } else {
            try {
                $password = $this->validatePassword((string) $password);
            } catch (\RuntimeException $e) {
                $io->error($e->getMessage());
                return null;
            }
        }

        return $password;
    }

    private function validatePassword(?string $password): string
    {
        $password = (string) $password;

        if (strlen($password) < 8) {
            throw new \RuntimeException('Sifre en az 8 karakter olmali.');
        }

        return $password;
    }
}
