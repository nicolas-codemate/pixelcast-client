<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Tracker\AllTimeHighStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Stores writing to a throwaway directory, removed when the test process ends, so that a test
 * needing one has nothing to set up nor to clean up.
 */
final class AllTimeHighStoreFactory
{
    private const string DATABASE_FILE_NAME = 'tracker-all-time-high.sqlite';

    public static function temporaryStore(?LoggerInterface $logger = null): AllTimeHighStore
    {
        return self::storeAt(self::temporaryDatabasePath(), $logger);
    }

    /**
     * The path of a database that does not exist yet, for tests that need several stores to share
     * one file or that watch when the file appears.
     */
    public static function temporaryDatabasePath(): string
    {
        return self::temporaryDirectory().'/'.self::DATABASE_FILE_NAME;
    }

    public static function storeAt(string $databaseFilePath, ?LoggerInterface $logger = null): AllTimeHighStore
    {
        return new AllTimeHighStore($databaseFilePath, $logger ?? new NullLogger());
    }

    /**
     * A regular file cannot hold a directory, so neither the parent nor the database can be created.
     */
    public static function unreachableStore(?LoggerInterface $logger = null): AllTimeHighStore
    {
        $blockingFile = self::temporaryDirectory().'/not-a-directory';
        new Filesystem()->dumpFile($blockingFile, '');

        return self::storeAt($blockingFile.'/'.self::DATABASE_FILE_NAME, $logger);
    }

    private static function temporaryDirectory(): string
    {
        $directoryPath = sys_get_temp_dir().'/pixelcast-tracker-ath-'.bin2hex(random_bytes(6));

        register_shutdown_function(static function () use ($directoryPath): void {
            new Filesystem()->remove($directoryPath);
        });

        return $directoryPath;
    }
}
