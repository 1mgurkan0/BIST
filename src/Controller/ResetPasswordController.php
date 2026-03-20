<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ResetPasswordRequestFormType;
use App\Form\ResetPasswordFormType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;

class ResetPasswordController extends AbstractController
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {}


    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        EntityManagerInterface $em,
        RateLimiterFactory $forgotPasswordLimiter,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('market');
        }

        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $limiter = $forgotPasswordLimiter->create('forgot_' . $request->getClientIp());
            if (!$limiter->consume(1)->isAccepted()) {
                $this->addFlash('error', 'Çok fazla istek. Lütfen biraz bekleyin.');
                return $this->redirectToRoute('app_forgot_password');
            }

            $email = $form->get('email')->getData();

            /** @var User|null $user */
            $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

            if ($user instanceof User) {
                $code = $user->generateResetCode();
                $em->flush();

                try {
                    $this->sendResetCode($user, $code);
                } catch (TransportExceptionInterface $e) {
                    $this->logger->error('Şifre sıfırlama maili gönderilemedi.', [
                        'email'     => $email,
                        'exception' => $e->getMessage(),
                    ]);
                }

                $this->logger->info('Şifre sıfırlama kodu gönderildi.', ['email' => $email]);
            }

            $request->getSession()->set('reset_password_email', $email);

            $this->addFlash(
                'success',
                $email . ' adresine 6 haneli şifre sıfırlama kodu gönderildi.'
            );

            return $this->redirectToRoute('app_reset_password');
        }

        return $this->render('User/Login/forgot_password.html.twig', [
            'requestForm' => $form->createView(),
        ]);
    }

    #[Route('/reset-password', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        RateLimiterFactory $resetPasswordLimiter,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('market');
        }

        $email = $request->getSession()->get('reset_password_email');

        if (!$email) {
            $this->addFlash('error', 'Geçersiz oturum. Lütfen tekrar şifre sıfırlama talebinde bulunun.');
            return $this->redirectToRoute('app_forgot_password');
        }

        /** @var User|null $user */
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user) {
            $request->getSession()->remove('reset_password_email');
            $this->addFlash('error', 'Kullanıcı bulunamadı.');
            return $this->redirectToRoute('app_forgot_password');
        }

        $form = $this->createForm(ResetPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $limiter = $resetPasswordLimiter->create('reset_' . $user->getId());
            if (!$limiter->consume(1)->isAccepted()) {
                $this->addFlash('error', 'Çok fazla hatalı deneme. Lütfen yeni kod isteyin.');
                return $this->redirectToRoute('app_reset_password');
            }

            $digits = $request->request->all('digit') ?? [];
            $code   = implode('', array_map('trim', $digits));

            if ($user->isResetCodeBlocked()) {
                $this->addFlash('error', 'Çok fazla başarısız deneme. Lütfen yeni kod isteyin.');
                return $this->redirectToRoute('app_forgot_password');
            }

            if (!$user->isResetCodeValid($code)) {
                $user->incrementResetAttempts();
                $em->flush();

                $remaining = 5 - $user->getResetAttempts();
                $this->addFlash('error', sprintf(
                    'Hatalı kod. %d deneme hakkınız kaldı.',
                    max(0, $remaining)
                ));

                return $this->redirectToRoute('app_reset_password');
            }

            $newPassword = $form->get('plainPassword')->getData();
            $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
            $user->clearResetCode();
            $em->flush();

            $request->getSession()->remove('reset_password_email');

            $this->logger->info('Şifre başarıyla sıfırlandı.', ['userId' => $user->getId()]);

            $this->addFlash('success', 'Şifreniz başarıyla güncellendi. Şimdi giriş yapabilirsiniz.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('User/Login/reset_password.html.twig', [
            'resetForm'  => $form->createView(),
            'email'      => $email,
            'expiresAt'  => $user->getResetCodeExpiresAt(),
            'isBlocked'  => $user->isResetCodeBlocked(),
        ]);
    }

    #[Route('/reset-password/resend', name: 'app_reset_resend', methods: ['POST'])]
    public function resend(
        Request $request,
        EntityManagerInterface $em,
        RateLimiterFactory $forgotPasswordLimiter,
    ): Response {
        $email = $request->getSession()->get('reset_password_email');

        if (!$email) {
            return $this->redirectToRoute('app_forgot_password');
        }

        $limiter = $forgotPasswordLimiter->create('forgot_' . $request->getClientIp());
        if (!$limiter->consume(1)->isAccepted()) {
            $this->addFlash('error', 'Çok fazla istek. Lütfen biraz bekleyin.');
            return $this->redirectToRoute('app_reset_password');
        }

        /** @var User|null $user */
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($user instanceof User) {
            $code = $user->generateResetCode();
            $em->flush();

            try {
                $this->sendResetCode($user, $code);
                $this->addFlash('success', 'Yeni şifre sıfırlama kodu gönderildi.');
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Resend reset maili gönderilemedi.', [
                    'email'     => $email,
                    'exception' => $e->getMessage(),
                ]);
                $this->addFlash('error', 'Kod gönderilemedi. Lütfen tekrar deneyin.');
            }
        } else {
            $this->addFlash('success', $email . ' adresine eğer kayıtlıysa yeni kod gönderildi.');
        }

        return $this->redirectToRoute('app_reset_password');
    }

    /** @throws TransportExceptionInterface */
    private function sendResetCode(User $user, string $code): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address('noreply@bam.com', 'BAM Sistem'))
            ->to($user->getEmail())
            ->subject('BAM — Şifre Sıfırlama Kodunuz: ' . $code)
            ->htmlTemplate('User/login/reset_password_email.html.twig')
            ->context([
                'user' => $user,
                'code' => $code,
            ]);

        $this->mailer->send($email);
    }
}