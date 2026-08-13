<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Config\SyncsConfigLoader;
use App\Schedule;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Contracts\Cache\CacheInterface;

final class SyncsConfigLoaderFactory
{
    private const string SCHEMA_FILE_NAME = 'pixelcast.schema.json';
    private const string FIXTURES_DIR = __DIR__.'/../Config/Fixtures';
    private const string SCHEDULE_NAME = 'default';

    public static function forConfigFile(string $configFilePath, ?LoggerInterface $logger = null): SyncsConfigLoader
    {
        return new SyncsConfigLoader($configFilePath, self::projectFilePath(self::SCHEMA_FILE_NAME), $logger ?? new NullLogger());
    }

    /**
     * The consumer of a scheduler test: the cache holds the state a restart reads back, so passing
     * the same one to two generators plays a restart rather than a fresh install.
     */
    public static function messageGeneratorForFixture(string $fixtureName, CacheInterface $scheduleState, ClockInterface $clock): MessageGenerator
    {
        $schedule = new Schedule($scheduleState, self::forConfigFile(self::FIXTURES_DIR.'/'.$fixtureName));

        return new MessageGenerator($schedule, self::SCHEDULE_NAME, $clock);
    }

    public static function projectFilePath(string $relativePath): string
    {
        return \dirname(__DIR__, 2).'/'.$relativePath;
    }
}
