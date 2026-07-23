<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
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
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Annotation\Route;

class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
        RateLimiterFactoryInterface $registrationLimiter,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('market');
        }

        $limiter = $registrationLimiter->create($request->getClientIp());
        if (!$limiter->consume(1)->isAccepted()) {
            $this->addFlash('error', 'Çok fazla deneme. Lütfen birkaç dakika sonra tekrar deneyin.');
            return $this->redirectToRoute('app_register');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $hashedPassword = $passwordHasher->hashPassword(
                $user,
                $form->get('plainPassword')->getData()
            );

            $code    = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires = (new \DateTimeImmutable('+2 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $request->getSession()->set('pending_user', [
                'email'     => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName'  => $user->getLastName(),
                'password'  => $hashedPassword,
                'code'      => $code,
                'expires'   => $expires,
                'attempts'  => 0,
            ]);

            try {
                $this->sendVerificationCode($user->getEmail(), $user->getFirstName(), $code);
            } catch (TransportExceptionInterface $e) {
                $request->getSession()->remove('pending_user');

                $this->logger->error('OTP maili gönderilemedi.', [
                    'email'     => $user->getEmail(),
                    'exception' => $e->getMessage(),
                ]);

                $this->addFlash('error', 'Doğrulama kodu gönderilemedi. Lütfen tekrar deneyin.');
                return $this->redirectToRoute('app_register');
            }

            $this->logger->info('Kayıt formu dolduruldu, OTP gönderildi.', [
                'email' => $user->getEmail(),
            ]);

            $this->addFlash('success', $user->getEmail() . ' adresine 6 haneli doğrulama kodu gönderildi.');

            return $this->redirectToRoute('app_verify_code');
        }

        return $this->render('User/registration/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }

    #[Route('/verify/code', name: 'app_verify_code', methods: ['GET', 'POST'])]
    public function verifyCode(
        Request $request,
        EntityManagerInterface $em,
        RateLimiterFactoryInterface $verifyCodeLimiter,
    ): Response {
        $pending = $request->getSession()->get('pending_user');

        if (!$pending) {
            return $this->redirectToRoute('app_register');
        }

        $email     = $pending['email'];
        $expiresAt = new \DateTimeImmutable($pending['expires'], new \DateTimeZone('UTC'));
        $isBlocked = $pending['attempts'] >= 5;

        if ($request->isMethod('POST')) {
            $limiter = $verifyCodeLimiter->create('verify_' . md5($email));
            if (!$limiter->consume(1)->isAccepted()) {
                $this->addFlash('error', 'Çok fazla hatalı deneme. Lütfen yeni kod isteyin.');
                return $this->redirectToRoute('app_verify_code');
            }

            if ($isBlocked) {
                $this->addFlash('error', 'Çok fazla başarısız deneme. Lütfen yeni kod isteyin.');
                return $this->redirectToRoute('app_verify_code');
            }

            $digits = $request->request->all('digit') ?? [];
            $code   = implode('', array_map('trim', $digits));

            if (new \DateTimeImmutable('now', new \DateTimeZone('UTC')) > $expiresAt) {
                $this->addFlash('error', 'Kodun süresi doldu. Lütfen yeni bir kod isteyin.');
                return $this->redirectToRoute('app_verify_code');
            }

            if (!hash_equals($pending['code'], $code)) {
                $pending['attempts']++;
                $request->getSession()->set('pending_user', $pending);

                $remaining = 5 - $pending['attempts'];
                $this->addFlash('error', sprintf(
                    'Hatalı kod. %d deneme hakkınız kaldı.',
                    max(0, $remaining)
                ));

                return $this->redirectToRoute('app_verify_code');
            }

            $existingUser = $em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existingUser) {
                $request->getSession()->remove('pending_user');
                $this->addFlash('error', 'Bu e-posta adresi zaten kayıtlı.');
                return $this->redirectToRoute('app_register');
            }

            $user = new User();
            $user->setEmail($pending['email']);
            $user->setFirstName($pending['firstName']);
            $user->setLastName($pending['lastName']);
            $user->setPassword($pending['password']);
            $user->setIsVerified(true);

            $em->persist($user);
            $em->flush();

            $request->getSession()->remove('pending_user');

            $this->logger->info('Hesap oluşturuldu.', ['userId' => $user->getId()]);

            $this->addFlash('success', 'Hesap başarıyla oluşturuldu! Şimdi giriş yapabilirsiniz.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('User/registration/verify_code.html.twig', [
            'email'     => $email,
            'expiresAt' => $expiresAt,
            'isBlocked' => $isBlocked,
        ]);
    }

    #[Route('/verify/resend', name: 'app_resend_code', methods: ['POST'])]
    public function resendCode(
        Request $request,
        RateLimiterFactoryInterface $resendVerificationLimiter,
    ): Response {
        $pending = $request->getSession()->get('pending_user');

        if (!$pending) {
            return $this->redirectToRoute('app_register');
        }

        $limiter = $resendVerificationLimiter->create('resend_' . $request->getClientIp());
        if (!$limiter->consume(1)->isAccepted()) {
            $this->addFlash('error', 'Çok fazla istek. Lütfen biraz bekleyin.');
            return $this->redirectToRoute('app_verify_code');
        }

        $code    = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = (new \DateTimeImmutable('+2 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $pending['code']     = $code;
        $pending['expires']  = $expires;
        $pending['attempts'] = 0;
        $request->getSession()->set('pending_user', $pending);

        try {
            $this->sendVerificationCode($pending['email'], $pending['firstName'], $code);
            $this->addFlash('success', 'Yeni doğrulama kodu gönderildi.');
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Resend OTP maili gönderilemedi.', [
                'email'     => $pending['email'],
                'exception' => $e->getMessage(),
            ]);
            $this->addFlash('error', 'Kod gönderilemedi. Lütfen tekrar deneyin.');
        }

        return $this->redirectToRoute('app_verify_code');
    }

    /** @throws TransportExceptionInterface */
    private function sendVerificationCode(string $email, string $firstName, string $code): void
    {
        $templatedEmail = (new TemplatedEmail())
            ->from(new Address('noreply@bam.com', 'BAM Sistem'))
            ->to($email)
            ->subject('BAM — Doğrulama Kodunuz: ' . $code)
            ->htmlTemplate('User/registration/verification_code_email.html.twig')
            ->context([
                'firstName' => $firstName,
                'code'      => $code,
            ]);

        $this->mailer->send($templatedEmail);
    }
}
