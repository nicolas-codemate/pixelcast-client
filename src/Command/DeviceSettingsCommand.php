<?php

declare(strict_types=1);

namespace App\Command;

use App\Client\Exception\PixelcastClientException;
use App\Client\PixelcastClientInterface;
use App\Client\Settings\SettingsSnapshot;
use App\Config\Exception\PixelCastConfigException;
use App\Config\SyncsConfigLoader;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The device keeps its settings across reboots, so this is run once, when the section is written or
 * changed, and not on a cycle like a sync group.
 */
#[AsCommand(
    name: 'app:device:settings',
    description: 'Pushes the device settings of pixelcast.yaml to the device',
)]
final class DeviceSettingsCommand extends Command
{
    public function __construct(
        private readonly SyncsConfigLoader $syncsConfigLoader,
        private readonly PixelcastClientInterface $pixelcastClient,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $deviceConfig = $this->syncsConfigLoader->load()->device;
        } catch (PixelCastConfigException $unusableConfig) {
            $io->error($unusableConfig->getMessage());

            return Command::INVALID;
        }

        if (null === $deviceConfig) {
            $io->error([
                'pixelcast.yaml declares no "device" section, so there is no setting to push.',
                'pixelcast.yaml.dist carries the shape of the section.',
            ]);

            return Command::INVALID;
        }

        try {
            $settingsPayload = $deviceConfig->toSettingsPayload($this->clock->now());
        } catch (PixelCastConfigException $unusableSection) {
            $io->error($unusableSection->getMessage());

            return Command::INVALID;
        }

        try {
            $this->pixelcastClient->pushSettings($settingsPayload);
        } catch (PixelcastClientException $pushFailure) {
            $io->error(\sprintf('The device did not take the settings: %s', $pushFailure->getMessage()));

            return Command::FAILURE;
        }

        try {
            $settingsSnapshot = $this->pixelcastClient->fetchSettings();
        } catch (PixelcastClientException $readBackFailure) {
            $io->warning([
                \sprintf('The settings were pushed, but reading the device back failed: %s', $readBackFailure->getMessage()),
                'Nothing says the device did not take them. Run the command again to see what it holds.',
            ]);

            return Command::SUCCESS;
        }

        $this->reportSettings($io, $settingsSnapshot);

        return Command::SUCCESS;
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
