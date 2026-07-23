<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Cache\CacheInterface;

#[AsCommand(
    name: 'app:production:check',
    description: 'Canliya cikis oncesi ortam, veritabani, cache ve lock kontrollerini yapar.',
)]
class ProductionCheckCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CacheInterface $cache,
        private readonly LockFactory $lockFactory,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(APP_SECRET)%')]
        private readonly string $appSecret,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri,
        #[Autowire('%env(GEMINI_API_KEY)%')]
        private readonly string $geminiApiKey,
        #[Autowire('%env(TELEGRAM_BOT_TOKEN)%')]
        private readonly string $telegramToken,
        #[Autowire('%env(TELEGRAM_CHAT_ID)%')]
        private readonly string $telegramChatId,
        #[Autowire('%env(MESSENGER_TRANSPORT_DSN)%')]
        private readonly string $messengerDsn,
        #[Autowire('%env(LOCK_DSN)%')]
        private readonly string $lockDsn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'strict',
            null,
            InputOption::VALUE_NONE,
            'Prod ortam zorunluluklarini uygula ve uyarilari hata say.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $strict = (bool) $input->getOption('strict') || $this->environment === 'prod';
        $checks = [];

        $checks[] = $this->check(
            'Runtime',
            !$strict || ($this->environment === 'prod' && !$this->debug),
            sprintf('APP_ENV=%s, debug=%s', $this->environment, $this->debug ? 'on' : 'off'),
            $strict ? 'fail' : 'warn'
        );
        $checks[] = $this->check(
            'APP_SECRET',
            strlen(trim($this->appSecret)) >= 32 && !preg_match('/change|secret|example/i', $this->appSecret),
            'En az 32 karakter ve tahmin edilemez olmali.'
        );
        $checks[] = $this->check(
            'DEFAULT_URI',
            !$strict || str_starts_with(strtolower($this->defaultUri), 'https://'),
            $strict ? 'Canlida HTTPS adresi olmali.' : 'Yerel ortamda HTTP kullanilabilir.',
            $strict ? 'fail' : 'warn'
        );

        foreach ([
            'GEMINI_API_KEY' => $this->geminiApiKey,
            'TELEGRAM_BOT_TOKEN' => $this->telegramToken,
            'TELEGRAM_CHAT_ID' => $this->telegramChatId,
            'MESSENGER_TRANSPORT_DSN' => $this->messengerDsn,
            'LOCK_DSN' => $this->lockDsn,
        ] as $name => $value) {
            $checks[] = $this->check($name, trim($value) !== '', 'Deger tanimli olmali.');
        }

        $checks[] = $this->probeDatabase();
        $checks[] = $this->probeCache();
        $checks[] = $this->probeLock();
        $checks[] = $this->check(
            'Yazma izinleri',
            is_writable($this->projectDir . '/var'),
            'var/ dizini PHP ve worker kullanicisi tarafindan yazilabilir olmali.'
        );

        foreach (['ctype', 'curl', 'iconv', 'json', 'mbstring', 'openssl', 'pdo_mysql'] as $extension) {
            $checks[] = $this->check('PHP ext-' . $extension, extension_loaded($extension), 'PHP eklentisi yuklu olmali.');
        }

        foreach (['intl', 'Zend OPcache'] as $extension) {
            $checks[] = $this->check(
                'PHP ' . $extension,
                extension_loaded($extension),
                'Canli performans ve yerellestirme icin onerilir.',
                $strict ? 'fail' : 'warn'
            );
        }

        $io->table(
            ['Kontrol', 'Durum', 'Aciklama'],
            array_map(fn(array $check): array => [
                $check['name'],
                $check['ok'] ? 'OK' : strtoupper($check['severity']),
                $check['message'],
            ], $checks)
        );

        $failures = array_filter($checks, fn(array $check): bool => !$check['ok'] && $check['severity'] === 'fail');
        if ($failures !== []) {
            $io->error(sprintf('%d kritik canliya cikis kontrolu basarisiz.', count($failures)));
            return Command::FAILURE;
        }

        $io->success('Uygulama canliya cikis kontrollerini gecti.');
        return Command::SUCCESS;
    }

    /**
     * @return array{name: string, ok: bool, message: string, severity: string}
     */
    private function probeDatabase(): array
    {
        try {
            return $this->check('Veritabani', (int) $this->connection->fetchOne('SELECT 1') === 1, 'Baglanti ve sorgu testi.');
        } catch (\Throwable) {
            return $this->check('Veritabani', false, 'Baglanti veya sorgu testi basarisiz.');
        }
    }

    /**
     * @return array{name: string, ok: bool, message: string, severity: string}
     */
    private function probeCache(): array
    {
        $key = 'production.check.' . bin2hex(random_bytes(8));

        try {
            $item = $this->cache->getItem($key);
            $item->set('ok')->expiresAfter(30);
            $saved = $this->cache->save($item);
            $read = $this->cache->getItem($key)->get() === 'ok';
            $this->cache->deleteItem($key);

            return $this->check('Cache', $saved && $read, 'Yazma ve okuma testi.');
        } catch (\Throwable) {
            return $this->check('Cache', false, 'Yazma veya okuma testi basarisiz.');
        }
    }

    /**
     * @return array{name: string, ok: bool, message: string, severity: string}
     */
    private function probeLock(): array
    {
        try {
            $lock = $this->lockFactory->createLock('production_check_probe', 5.0, false);
            $acquired = $lock->acquire();
            if ($acquired) {
                $lock->release();
            }

            return $this->check('Lock', $acquired, 'Dagitik is kilidi alma ve birakma testi.');
        } catch (\Throwable) {
            return $this->check('Lock', false, 'Lock testi basarisiz.');
        }
    }

    /**
     * @return array{name: string, ok: bool, message: string, severity: string}
     */
    private function check(string $name, bool $ok, string $message, string $severity = 'fail'): array
    {
        return compact('name', 'ok', 'message', 'severity');
    }
}
