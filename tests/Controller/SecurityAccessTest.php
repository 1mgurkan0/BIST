<?php

namespace App\Tests\Controller;

use App\DTO\MarketDataDto;
use App\Entity\KapNews;
use App\Entity\User;
use App\Service\YahooFinanceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SecurityAccessTest extends WebTestCase
{
    public function testAiReportRequiresLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/ai-reports');

        self::assertResponseRedirects('/login');
    }

    public function testLoginPageIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Tekrar Hoş Geldiniz');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        self::assertResponseHasHeader('Content-Security-Policy');
    }

    public function testAdminCanLogInAndReachDashboard(): void
    {
        $client = static::createClient();
        $password = 'FunctionalLogin!2026';
        $user = $this->user()->setEmail('login-' . bin2hex(random_bytes(6)) . '@example.test');
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, $password));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        $crawler = $client->request('GET', '/login');
        $csrfToken = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/login', [
            'email' => $user->getEmail(),
            'password' => $password,
            '_csrf_token' => $csrfToken,
        ]);

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Hisse Analiz Merkezi');
    }

    public function testVerifiedNonAdminCannotLogIn(): void
    {
        $client = static::createClient();
        $password = 'NonAdminLogin!2026';
        $user = (new User())
            ->setEmail('non-admin-' . bin2hex(random_bytes(6)) . '@example.test')
            ->setFirstName('Non')
            ->setLastName('Admin')
            ->setRoles([])
            ->setIsVerified(true);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, $password));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        $crawler = $client->request('GET', '/login');
        $csrfToken = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/login', [
            'email' => $user->getEmail(),
            'password' => $password,
            '_csrf_token' => $csrfToken,
        ]);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'E-posta adresi veya sifre hatali.');
    }

    public function testHealthEndpointsArePublic(): void
    {
        $client = static::createClient();

        $client->request('GET', '/health/live');
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $client->getResponse()->getContent());

        $client->request('GET', '/health/ready');
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"status":"ready"}', (string) $client->getResponse()->getContent());
    }

    public function testLogoutRejectsGetRequest(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistedUser());

        $client->request('GET', '/logout');
        self::assertResponseStatusCodeSame(405);
    }

    public function testLegacyAccountFlowsAreDisabledAfterLogin(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistedUser());

        foreach (['/register', '/verify/code', '/forgot-password', '/reset-password'] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(403, $path . ' must remain disabled.');
        }
    }

    public function testInvalidMarketSymbolDoesNotReachProvider(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistedUser());

        $client->request('GET', '/?symbol=../ASELS');
        self::assertResponseRedirects('/');
    }

    public function testValidMarketSearchRendersLastSuccessfulYahooQuote(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistedUser());

        $fetchedAt = new \DateTimeImmutable('-2 minutes');
        $quote = new MarketDataDto(
            symbol: 'AKBNK',
            price: 66.45,
            open: 66.80,
            high: 67.10,
            low: 66.20,
            previousClose: 66.85,
            volume: 42_000_000,
            fetchedAt: $fetchedAt,
        );
        $yahoo = $this->createMock(YahooFinanceService::class);
        $yahoo->expects(self::once())
            ->method('fetchOneWithStatus')
            ->with('AKBNK')
            ->willReturn([
                'symbol' => 'AKBNK',
                'data' => $quote,
                'lastSuccessful' => $quote,
                'source' => 'last_success',
                'status' => 'rate_limited',
                'httpStatus' => 429,
                'message' => 'Yahoo 429 verdi. Son basarili veri gosteriliyor.',
                'isStale' => true,
                'fetchedAt' => $fetchedAt,
                'lastSuccessfulAt' => $fetchedAt,
            ]);
        static::getContainer()->set(YahooFinanceService::class, $yahoo);

        $news = (new KapNews())
            ->setKapId('test-' . bin2hex(random_bytes(6)))
            ->setTitle('[AKBNK] Test KAP bildirimi')
            ->setContent('Resmi KAP ozeti AI analizi olmadan da gorunmeli.')
            ->setStockCodes(['AKBNK'])
            ->setPublishedAt(new \DateTimeImmutable())
            ->setIsAnalyzed(false);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($news);
        $em->flush();

        $client->request('GET', '/?symbol=AKBNK');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.display-3', 'AKBNK');
        self::assertSelectorTextContains('body', '!429');
        self::assertSelectorTextContains('body', 'AI ANALIZI BEKLIYOR');
        self::assertSelectorTextContains('body', 'Resmi KAP ozeti AI analizi olmadan da gorunmeli.');
        self::assertSelectorTextNotContains('body', 'Veri Bulunamadi');
    }

    public function testPortfolioDeleteAndAnalysisRejectGetRequests(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user());

        $client->request('GET', '/portfolio/delete/1');
        self::assertResponseStatusCodeSame(405);

        $client->request('GET', '/portfolio/analyze/1');
        self::assertResponseStatusCodeSame(405);
    }

    public function testAuthenticatedDashboardAndSnapshotRoutesRender(): void
    {
        $client = static::createClient();
        $client->loginUser($this->persistedUser());

        foreach (['/', '/portfolio', '/watchlist', '/ai-reports', '/ai-reports?filter=opportunity'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }

        foreach (['/api/portfolio/live', '/watchlist/api/live'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            self::assertResponseFormatSame('json');
        }
    }

    private function user(): User
    {
        return (new User())
            ->setEmail('security-test@example.test')
            ->setFirstName('Security')
            ->setLastName('Test')
            ->setPassword('not-used')
            ->setRoles(['ROLE_ADMIN'])
            ->setIsVerified(true);
    }

    private function persistedUser(): User
    {
        $user = $this->user()->setEmail('security-' . bin2hex(random_bytes(6)) . '@example.test');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
