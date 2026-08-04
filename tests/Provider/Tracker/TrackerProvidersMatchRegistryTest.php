<?php

declare(strict_types=1);

namespace App\Tests\Provider\Tracker;

use App\Config\Sync\SyncGroupRegistry;
use App\Config\Sync\TrackerSyncConfig;
use App\Provider\Tracker\TrackerProviderInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * SyncTrackerHandler picks its provider by sync type at runtime, so a tracker group
 * declared without its provider only shows up as a failed cycle in production.
 */
final class TrackerProvidersMatchRegistryTest extends KernelTestCase
{
    public function testEveryTrackerSyncGroupOfTheRegistryIsServedByExactlyOneProvider(): void
    {
        $syncTypesFromProviders = $this->syncTypesServedByProviders();
        $syncTypesFromRegistry = self::trackerSyncTypesOfRegistry();

        sort($syncTypesFromProviders);
        sort($syncTypesFromRegistry);

        self::assertSame($syncTypesFromRegistry, $syncTypesFromProviders);
    }

    /**
     * @return list<string>
     */
    private function syncTypesServedByProviders(): array
    {
        self::bootKernel();
        $container = self::getContainer();

        $syncTypes = [];
        foreach (self::trackerProviderClasses() as $trackerProviderClass) {
            $trackerProvider = $container->get($trackerProviderClass);
            self::assertInstanceOf(TrackerProviderInterface::class, $trackerProvider);

            $syncTypes[] = $trackerProvider->syncType();
        }

        return $syncTypes;
    }

    /**
     * @return list<class-string<TrackerProviderInterface>>
     */
    private static function trackerProviderClasses(): array
    {
        $providerFiles = glob(\dirname(__DIR__, 3).'/src/Provider/Tracker/*.php');
        self::assertIsArray($providerFiles);

        $trackerProviderClasses = [];
        foreach ($providerFiles as $providerFile) {
            /** @var class-string $candidateClass */
            $candidateClass = 'App\\Provider\\Tracker\\'.basename($providerFile, '.php');
            if (is_subclass_of($candidateClass, TrackerProviderInterface::class)) {
                $trackerProviderClasses[] = $candidateClass;
            }
        }

        return $trackerProviderClasses;
    }

    /**
     * @return list<string>
     */
    private static function trackerSyncTypesOfRegistry(): array
    {
        return array_values(array_filter(
            SyncGroupRegistry::syncTypes(),
            static fn (string $syncType): bool => is_subclass_of(SyncGroupRegistry::syncGroupClassFor($syncType), TrackerSyncConfig::class),
        ));
    }
}
