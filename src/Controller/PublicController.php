<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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

    #[Route("/iletisim", name: "app_contact")]
    public function contact(): Response
    {
        return $this->render("public/contact.html.twig");
    }

    #[Route("/blog", name: "app_blog")]
    public function blog(): Response
    {
        return $this->render("public/blog.html.twig");
    }

    #[Route("/sitemap.xml", name: "app_sitemap", defaults: ["_format" => "xml"])]
    public function sitemap(): Response
    {
        $urls = [];
        $hostname = "https://www.bamterminal.com.tr";

        // Routes to include in sitemap
        // Gecici olarak bos olan public sayfalar (hakkimizda, iletisim, vb.) sitemapten cikarildi
        $routes = [
            ["name" => "app-home", "priority" => "1.0", "changefreq" => "daily"],
            ["name" => "app_login", "priority" => "0.8", "changefreq" => "weekly"],
            ["name" => "app_register", "priority" => "0.8", "changefreq" => "weekly"],
        ];

        foreach ($routes as $route) {
            $urls[] = [
                "loc" => $hostname . $this->generateUrl($route["name"]),
                "changefreq" => $route["changefreq"],
                "priority" => $route["priority"],
            ];
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        
        foreach ($urls as $url) {
            $xml .= "   <url>\n";
            $xml .= "      <loc>" . htmlspecialchars($url["loc"]) . "</loc>\n";
            $xml .= "      <changefreq>" . $url["changefreq"] . "</changefreq>\n";
            $xml .= "      <priority>" . $url["priority"] . "</priority>\n";
            $xml .= "   </url>\n";
        }
        $xml .= "</urlset>";

        return new Response($xml, 200, ["Content-Type" => "text/xml"]);
    }
}
