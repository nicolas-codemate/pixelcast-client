<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Client\Exception\DeviceUnreachableException;
use App\Client\Exception\ResourceNotFoundException;
use App\Client\Icon\IconsSnapshot;
use App\Command\IconsSeedCommand;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use App\Tests\Stub\RecordingPixelcastClientStub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class IconsSeedCommandTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/../Config/Fixtures';
    private const string FILE_WITH_ICONS = 'syncs-icons-seed.yaml';
    private const string FILE_WITHOUT_ICONS = 'syncs-trackers-enabled.yaml';
    private const string FILE_THE_LOADER_REFUSES = 'syncs-invalid-syntax.yaml';
    private const int BITCOIN_LAMETRIC_ID = 15392;
    private const int ETHEREUM_LAMETRIC_ID = 45056;
    private const int STOCK_LAMETRIC_ID = 40160;

    private string|false $terminalWidthBeforeTheTest;

    protected function setUp(): void
    {
        parent::setUp();

        // Console blocks wrap at the terminal width, and a width of 80 would fold the messages these
        // assertions read.
        $this->terminalWidthBeforeTheTest = getenv('COLUMNS');
        putenv('COLUMNS=200');
    }

    protected function tearDown(): void
    {
        putenv(false === $this->terminalWidthBeforeTheTest ? 'COLUMNS' : 'COLUMNS='.$this->terminalWidthBeforeTheTest);

        parent::tearDown();
    }

    public function testTheMissingIconsAreDownloadedAndThePresentOnesAreSkipped(): void
    {
        $client = new RecordingPixelcastClientStub();
        $client->iconsSnapshotToReturn = self::snapshotCarrying('bitcoin');

        $tester = self::createTester(self::FILE_WITH_ICONS, $client);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([
            ['id' => self::ETHEREUM_LAMETRIC_ID, 'name' => 'ethereum'],
            ['id' => self::STOCK_LAMETRIC_ID, 'name' => 'stock'],
        ], $client->downloadedLaMetricIcons);
        self::assertStringContainsString('bitcoin: already on the device', $tester->getDisplay());
    }

    public function testAnIconWithoutALaMetricIdIsReportedAndNamesTheUploadFallback(): void
    {
        $client = new RecordingPixelcastClientStub();
        $client->iconsSnapshotToReturn = self::snapshotCarrying('bitcoin');

        $tester = self::createTester(self::FILE_WITH_ICONS, $client);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $tester->getDisplay();
        self::assertStringContainsString('solana: no lametricId configured', $display);
        self::assertStringContainsString('app:icons:upload solana', $display);
        self::assertNotContains('solana', array_column($client->downloadedLaMetricIcons, 'name'));
    }

    public function testAnIconTwoItemsShareIsDownloadedOnceFromTheItemCarryingTheMapping(): void
    {
        $client = new RecordingPixelcastClientStub();
        $client->iconsSnapshotToReturn = self::snapshotCarrying('bitcoin', 'ethereum');

        $tester = self::createTester(self::FILE_WITH_ICONS, $client);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            [['id' => self::STOCK_LAMETRIC_ID, 'name' => 'stock']],
            $client->downloadedLaMetricIcons,
        );
    }

    public function testForceDownloadsTheIconsTheDeviceAlreadyCarries(): void
    {
        $client = new RecordingPixelcastClientStub();
        $client->iconsSnapshotToReturn = self::snapshotCarrying('bitcoin');

        $tester = self::createTester(self::FILE_WITH_ICONS, $client);

        $exitCode = $tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['id' => self::BITCOIN_LAMETRIC_ID, 'name' => 'bitcoin'], $client->downloadedLaMetricIcons[0] ?? null);
        self::assertStringNotContainsString('already on the device, skipped', $tester->getDisplay());
        self::assertSame(0, $client->listIconsCallCount);
    }

    public function testALaMetricIconTheDeviceRefusesFailsTheCommand(): void
    {
        $client = new RecordingPixelcastClientStub(
            laMetricIconFailures: [self::ETHEREUM_LAMETRIC_ID => ResourceNotFoundException::forPath('/icons/lametric')],
        );

        $tester = self::createTester(self::FILE_WITH_ICONS, $client);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);

        $display = $tester->getDisplay();
        self::assertStringContainsString('could not be downloaded', $display);
        self::assertStringContainsString('ethereum', $display);
        self::assertSame([
            ['id' => self::BITCOIN_LAMETRIC_ID, 'name' => 'bitcoin'],
            ['id' => self::STOCK_LAMETRIC_ID, 'name' => 'stock'],
        ], $client->downloadedLaMetricIcons);
    }

    public function testADeviceThatCannotBeListedFailsTheCommandBeforeAnyDownload(): void
    {
        $client = new RecordingPixelcastClientStub(DeviceUnreachableException::forPath('/icons', new \RuntimeException('no route to host')));

        $tester = self::createTester(self::FILE_WITH_ICONS, $client);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('no route to host', self::singleLineDisplay($tester));
        self::assertSame([], $client->downloadedLaMetricIcons);
    }

    public function testAConfigurationWhoseItemsNameNoIconSeedsNothing(): void
    {
        $client = new RecordingPixelcastClientStub();

        $tester = self::createTester(self::FILE_WITHOUT_ICONS, $client);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('nothing to seed', self::singleLineDisplay($tester));
        self::assertSame([], $client->downloadedLaMetricIcons);
    }

    public function testAnUnusableConfigurationStopsTheCommand(): void
    {
        $client = new RecordingPixelcastClientStub();

        $tester = self::createTester(self::FILE_THE_LOADER_REFUSES, $client);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertSame([], $client->downloadedLaMetricIcons);
    }

    private static function singleLineDisplay(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private static function createTester(string $fixtureFileName, RecordingPixelcastClientStub $client): CommandTester
    {
        return new CommandTester(new IconsSeedCommand(
            SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.$fixtureFileName),
            $client,
        ));
    }

    private static function snapshotCarrying(string ...$iconNames): IconsSnapshot
    {
        return IconsSnapshot::fromResponseBody([
            'icons' => array_map(
                static fn (string $iconName): array => ['name' => $iconName, 'filename' => $iconName.'.png', 'size' => 256],
                $iconNames,
            ),
            'count' => \count($iconNames),
        ]);
    }
}
