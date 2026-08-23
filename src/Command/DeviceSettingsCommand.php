<?php

declare(strict_types=1);

namespace App\Command;

use App\Client\PixelcastClientInterface;
use App\Client\Settings\SettingsPayload;
use App\Client\Settings\SettingsSnapshot;
use App\Config\Device\DeviceConfig;
use App\Config\SyncsConfig;
use App\Config\SyncsConfigLoader;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The device keeps its settings across reboots, so this is run once, when the section is written or
 * changed, and not on a cycle like a sync group.
 *
 * @extends AbstractDevicePushCommand<DeviceConfig, SettingsPayload, SettingsSnapshot>
 */
#[AsCommand(
    name: 'app:device:settings',
    description: 'Pushes the device settings of pixelcast.yaml to the device',
)]
final class DeviceSettingsCommand extends AbstractDevicePushCommand
{
    public function __construct(
        SyncsConfigLoader $syncsConfigLoader,
        PixelcastClientInterface $pixelcastClient,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($syncsConfigLoader, $pixelcastClient);
    }

    protected function sectionKey(): string
    {
        return 'device';
    }

    protected function missingSectionNoun(): string
    {
        return 'setting';
    }

    protected function pushedThingName(): string
    {
        return 'settings';
    }

    protected function sectionFromConfig(SyncsConfig $syncsConfig): ?DeviceConfig
    {
        return $syncsConfig->device;
    }

    /**
     * @param DeviceConfig $section
     */
    protected function toPayload(object $section): SettingsPayload
    {
        return $section->toSettingsPayload($this->clock->now());
    }

    /**
     * @param SettingsPayload $payload
     */
    protected function pushPayload(object $payload): void
    {
        $this->pixelcastClient->pushSettings($payload);
    }

    protected function readBack(): SettingsSnapshot
    {
        return $this->pixelcastClient->fetchSettings();
    }

    /**
     * @param SettingsSnapshot $snapshot
     */
    protected function report(SymfonyStyle $io, object $snapshot): void
    {
        $this->reportSettings($io, $snapshot);
    }

    private function reportSettings(SymfonyStyle $io, SettingsSnapshot $settingsSnapshot): void
    {
        $io->success([
            'The device section of pixelcast.yaml is now on the device.',
            'The panel keeps these settings across reboots, so this is run once, when the section changes.',
        ]);

        $rows = [];

        foreach (self::describeSettings($settingsSnapshot) as $settingName => $settingValue) {
            if (null !== $settingValue) {
                $rows[] = [$settingName, $settingValue];
            }
        }

        if ([] === $rows) {
            $io->note('The device reports no setting at all.');

            return;
        }

        $io->table(['Setting', 'On the device'], $rows);
    }

    /**
     * @return array<string, string|null> null for a setting the device did not report, which then carries no row
     */
    private static function describeSettings(SettingsSnapshot $settingsSnapshot): array
    {
        return [
            'brightness' => null === $settingsSnapshot->brightness ? null : (string) $settingsSnapshot->brightness,
            'autoRotate' => self::describeAutoRotate($settingsSnapshot->autoRotate),
            'defaultDuration' => self::describeMilliseconds($settingsSnapshot->defaultDurationMilliseconds),
            'weatherDuration' => self::describeMilliseconds($settingsSnapshot->weatherDurationMilliseconds),
            'ntp.server' => $settingsSnapshot->ntpServer,
            'ntp.tz_posix' => $settingsSnapshot->ntpTimezonePosix,
        ];
    }

    private static function describeAutoRotate(?bool $autoRotate): ?string
    {
        return match ($autoRotate) {
            true => 'yes',
            false => 'no',
            null => null,
        };
    }

    private static function describeMilliseconds(?int $milliseconds): ?string
    {
        return null === $milliseconds ? null : $milliseconds.' ms';
    }
}
