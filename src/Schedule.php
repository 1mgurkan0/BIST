<?php

namespace App;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run

            ->add(RecurringMessage::cron(
                '* 10-18 * * 1-5',
                new RunCommandMessage('app:market:fetch-bulk'),
                'Europe/Istanbul'
            ))

            ->add(RecurringMessage::cron(
                '*/2 10-18 * * 1-5',
                new RunCommandMessage('app:prices:refresh'),
                'Europe/Istanbul'
            ))
            ->add(RecurringMessage::cron(
                '1-59/2 10-18 * * 1-5',
                new RunCommandMessage('app:alerts:check'),
                'Europe/Istanbul'
            ))
            ->add(RecurringMessage::cron(
                '12 18 * * 1-5',
                new RunCommandMessage('app:kap-crawl --days=7 --all-bist'),
                'Europe/Istanbul'
            ))
            ->add(RecurringMessage::cron(
                '16 18 * * 1-5',
                new RunCommandMessage('app:run-analysis --limit=5 --threshold=75'),
                'Europe/Istanbul'
            ))
            ->add(RecurringMessage::cron(
                '25 18 * * 1-5',
                new RunCommandMessage('app:daily-ai-report'),
                'Europe/Istanbul'
            ))
            ->add(RecurringMessage::cron(
                '30 10,14,16 * * 1-5', // Sabah 10:30, 14:30, 16:30
                new RunCommandMessage('app:opportunities:scan'),
                'Europe/Istanbul'
            ))
            ->add(RecurringMessage::cron(
                '15 18 * * 1-5', // Kapanış (18:15)
                new RunCommandMessage('app:opportunities:scan'),
                'Europe/Istanbul'
            ))
            ->add(RecurringMessage::cron(
                '35 10,14,16 * * 1-5', // Scan'den 5 dk sonra AI raporu
                new RunCommandMessage('app:daily-ai-report --opportunities'),
                'Europe/Istanbul'
            ))
            ->add(RecurringMessage::cron(
                '20 18 * * 1-5', // Kapanış Scan'inden 5 dk sonra AI raporu
                new RunCommandMessage('app:daily-ai-report --opportunities'),
                'Europe/Istanbul'
            ))
        ;
    }
}
