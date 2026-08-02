<?php

declare(strict_types=1);

namespace App;

use App\Message\SyncWeatherMessage;
use App\Scheduler\SyncMessageRegistry;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
final class Schedule implements ScheduleProviderInterface, SyncMessageRegistry
{
    private const string WEATHER_TYPE = 'weather';
    private const string WEATHER_FREQUENCY = '30 minutes';

    private ?SymfonySchedule $schedule = null;

    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function syncMessages(): array
    {
        return array_map(
            static fn (array $syncDefinition): object => $syncDefinition['message'],
            self::syncDefinitions(),
        );
    }

    public function getSchedule(): SymfonySchedule
    {
        if (null !== $this->schedule) {
            return $this->schedule;
        }

        $schedule = new SymfonySchedule()
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);

        foreach (self::syncDefinitions() as $syncDefinition) {
            $schedule->add(RecurringMessage::every($syncDefinition['frequency'], $syncDefinition['message']));
        }

        $this->schedule = $schedule;

        return $this->schedule;
    }

    /**
     * Single place where a sync type is declared: syncMessages() and getSchedule() both project this map.
     *
     * @return array<string, array{message: object, frequency: string}>
     */
    private static function syncDefinitions(): array
    {
        return [
            self::WEATHER_TYPE => [
                'message' => new SyncWeatherMessage(),
                'frequency' => self::WEATHER_FREQUENCY,
            ],
        ];
    }
}
