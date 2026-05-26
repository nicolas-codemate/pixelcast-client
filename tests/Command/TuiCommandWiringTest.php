<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\TuiCommand;
use App\Config\PixelCastConfigLoader;
use App\Config\PixelCastConfigWriter;
use App\Tui\Configuration\ConfigurationFieldValidator;
use App\Tui\DeviceStatus\StatsHttpClient;
use App\Tui\DeviceStatus\StatsTransport;
use App\Tui\Inspector\InspectorHttpClient;
use App\Tui\Inspector\InspectorTransport;
use App\Tui\ResetSim\ResetSimulatorAction;
use App\Tui\Scenarios\ScenarioCatalog;
use App\Tui\Scenarios\ScenarioDispatcher;
use App\Tui\Scenarios\Transport\ScenarioHttpClient;
use App\Tui\Scenarios\Validation\OutboundPayloadValidator;
use App\Tui\SyncNow\SyncNowDispatcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TuiCommandWiringTest extends KernelTestCase
{
    public function testTuiCommandResolvesFromContainerWithoutDiErrors(): void
    {
        self::bootKernel();

        $command = self::getContainer()->get(TuiCommand::class);

        self::assertInstanceOf(TuiCommand::class, $command);
    }

    public function testScenarioCatalogResolvesFromContainer(): void
    {
        self::bootKernel();

        $catalog = self::getContainer()->get(ScenarioCatalog::class);

        self::assertInstanceOf(ScenarioCatalog::class, $catalog);
    }

    public function testOutboundPayloadValidatorResolvesFromContainer(): void
    {
        self::bootKernel();

        $validator = self::getContainer()->get(OutboundPayloadValidator::class);

        self::assertInstanceOf(OutboundPayloadValidator::class, $validator);
    }

    public function testScenarioDispatcherResolvesFromContainer(): void
    {
        self::bootKernel();

        $dispatcher = self::getContainer()->get(ScenarioDispatcher::class);

        self::assertInstanceOf(ScenarioDispatcher::class, $dispatcher);
    }

    public function testScenarioHttpClientResolvesFromContainer(): void
    {
        self::bootKernel();

        $httpClient = self::getContainer()->get(ScenarioHttpClient::class);

        self::assertInstanceOf(ScenarioHttpClient::class, $httpClient);
    }

    public function testSyncNowDispatcherResolvesFromContainer(): void
    {
        self::bootKernel();

        $syncNowDispatcher = self::getContainer()->get(SyncNowDispatcher::class);

        self::assertInstanceOf(SyncNowDispatcher::class, $syncNowDispatcher);
    }

    public function testResetSimulatorActionResolvesFromContainer(): void
    {
        self::bootKernel();

        $resetSimulatorAction = self::getContainer()->get(ResetSimulatorAction::class);

        self::assertInstanceOf(ResetSimulatorAction::class, $resetSimulatorAction);
    }

    public function testPixelCastConfigLoaderResolvesFromContainer(): void
    {
        self::bootKernel();

        $loader = self::getContainer()->get(PixelCastConfigLoader::class);

        self::assertInstanceOf(PixelCastConfigLoader::class, $loader);
    }

    public function testPixelCastConfigWriterResolvesFromContainer(): void
    {
        self::bootKernel();

        $writer = self::getContainer()->get(PixelCastConfigWriter::class);

        self::assertInstanceOf(PixelCastConfigWriter::class, $writer);
    }

    public function testConfigurationFieldValidatorResolvesFromContainer(): void
    {
        self::bootKernel();

        $validator = self::getContainer()->get(ConfigurationFieldValidator::class);

        self::assertInstanceOf(ConfigurationFieldValidator::class, $validator);
    }

    public function testStatsTransportResolvesToStatsHttpClient(): void
    {
        self::bootKernel();

        $transport = self::getContainer()->get(StatsTransport::class);

        self::assertInstanceOf(StatsHttpClient::class, $transport);
    }

    public function testInspectorTransportResolvesToInspectorHttpClient(): void
    {
        self::bootKernel();

        $transport = self::getContainer()->get(InspectorTransport::class);

        self::assertInstanceOf(InspectorHttpClient::class, $transport);
    }
}
