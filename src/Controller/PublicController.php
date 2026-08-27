<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Annotation\Route;

class PublicController extends AbstractController
{
    #[Route("/hakkimizda", name: "app_about")]
    public function about(): Response
    {
        return $this->render("public/about.html.twig");
    }

    #[Route("/yasal-uyari", name: "app_legal")]
    public function legal(): Response
    {
        return $this->render("public/legal.html.twig");
    }

    #[Route("/iletisim", name: "app_contact", methods: ["GET", "POST"])]
    public function contact(Request $request, MailerInterface $mailer): Response
    {
        $success = false;
        $error = null;

        if ($request->isMethod('POST')) {
            $firstName = $request->request->get('firstName');
            $lastName = $request->request->get('lastName');
            $email = $request->request->get('email');
            $subject = $request->request->get('subject');
            $message = $request->request->get('message');

            if ($firstName && $lastName && $email && $subject && $message) {
                try {
                    $fullName = trim($firstName . ' ' . $lastName);
                    
                    $htmlBody = sprintf(
                        '<p><strong>Gönderen:</strong> %s (%s)</p><p><strong>Konu:</strong> %s</p><p><strong>Mesaj:</strong></p><p>%s</p>',
                        htmlspecialchars($fullName),
                        htmlspecialchars($email),
                        htmlspecialchars($subject),
                        nl2br(htmlspecialchars($message))
                    );
                    
                    $textBody = sprintf(
                        "Gönderen: %s (%s)\nKonu: %s\nMesaj:\n%s",
                        $fullName,
                        $email,
                        $subject,
                        $message
                    );

                    $emailMessage = (new Email())
                        ->from('muhammedgurkanaltunbas@gmail.com')
                        ->to('muhammedgurkanaltunbas@gmail.com')
                        ->subject('BAM Terminal Form: ' . $subject)
                        ->text($textBody)
                        ->html($htmlBody);

                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $emailMessage->replyTo($email);
                    }

                    $mailer->send($emailMessage);
                    $success = true;
                } catch (\Throwable $e) {
                    $error = 'Hata Detayı: ' . $e->getMessage();
                }
            } else {
                $error = 'Lütfen tüm alanları doldurun.';
            }
        }

        return $this->render("public/contact.html.twig", [
            'success' => $success,
            'error' => $error
        ]);
    }

    #[Route("/blog", name: "app_blog")]
    public function blog(): Response
    {
        return $this->render("public/blog.html.twig");
    }

    #[Route("/sitemap.xml", name: "app_sitemap", defaults: ["_format" => "xml"])]
    public function sitemap(): Response
    {
        return new Response("xml", 200, ["Content-Type" => "text/xml"]);
    }
}
