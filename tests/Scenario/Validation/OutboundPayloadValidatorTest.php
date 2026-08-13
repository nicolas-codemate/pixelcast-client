<?php

declare(strict_types=1);

namespace App\Tests\Scenario\Validation;

use App\Client\Color;
use App\Client\CustomApp\CustomAppPayload;
use App\Client\CustomApp\Zone;
use App\Client\Gauge\GaugePayload;
use App\Client\Gauge\GaugeRow;
use App\Client\StaleBehavior;
use App\Client\Text\PolymorphicTextField;
use App\Client\Text\TextSegment;
use App\Scenario\ScenarioCatalog;
use App\Scenario\ScenarioDefinition;
use App\Scenario\Validation\OutboundOpenApiValidatorFactory;
use App\Scenario\Validation\OutboundPayloadValidator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OutboundPayloadValidatorTest extends KernelTestCase
{
    private const string TEST_DEVICE_BASE_URL = 'http://simulator:8080/api';

    private OutboundPayloadValidator $validator;
    private ScenarioCatalog $catalog;

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function gaugeBodyOutsideTheSpecProvider(): iterable
    {
        yield 'percent above the ceiling' => [['rows' => [['label' => '5h', 'percent' => 101]]]];
        yield 'one row over the limit' => [['rows' => array_fill(0, 10, ['label' => '5h', 'percent' => 41])]];
        yield 'malformed color' => [['rows' => [['label' => '5h', 'percent' => 41, 'color' => 'green']]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $projectDir = self::getContainer()->getParameter('kernel.project_dir');

        $factory = new OutboundOpenApiValidatorFactory($projectDir, self::TEST_DEVICE_BASE_URL);
        $this->validator = new OutboundPayloadValidator(
            $factory->create(),
            new Psr17Factory(),
            self::TEST_DEVICE_BASE_URL,
        );
        $this->catalog = new ScenarioCatalog();
    }

    public function testWeatherScenarioPayloadValidatesAgainstSpec(): void
    {
        $weather = $this->catalog->findById('weather', true);
        self::assertNotNull($weather);

        $result = $this->validator->validate($weather);

        self::assertTrue($result->valid, $result->errorMessage ?? '');
        self::assertNull($result->errorMessage);
    }

    public function testTrackerScenarioWithQueryStringValidatesAgainstSpec(): void
    {
        $tracker = $this->catalog->findById('tracker-btc', true);
        self::assertNotNull($tracker);

        $result = $this->validator->validate($tracker);

        self::assertTrue($result->valid, $result->errorMessage ?? '');
    }

    public function testIconRegisterScenarioPayloadValidatesAgainstSpec(): void
    {
        $iconRegister = $this->catalog->findById('icon-register', true);
        self::assertNotNull($iconRegister);

        $result = $this->validator->validate($iconRegister);

        self::assertTrue($result->valid, $result->errorMessage ?? '');
    }

    public function testNotificationMissingRequiredTextFailsValidation(): void
    {
        $invalidNotification = new ScenarioDefinition(
            id: 'notification-invalid',
            label: 'Notification - missing text',
            description: 'invalid: required text field stripped',
            httpMethod: 'POST',
            path: '/notify',
            body: ['icon' => 'mail'],
        );

        $result = $this->validator->validate($invalidNotification);

        self::assertFalse($result->valid);
        self::assertNotNull($result->errorMessage);
        self::assertStringContainsStringIgnoringCase('text', $result->errorMessage);
    }

    public function testValidateRequestAcceptsAWeatherPayload(): void
    {
        $result = $this->validator->validateRequest('POST', '/weather', [], [
            'current' => ['icon' => 'w_rain', 'temp' => 9],
        ]);

        self::assertTrue($result->valid, $result->errorMessage ?? '');
        self::assertNull($result->errorMessage);
    }

    public function testValidateRequestRejectsAnEightDayForecast(): void
    {
        $forecast = array_fill(0, 8, ['day' => 'LUN', 'icon' => 'w_rain', 'temp_min' => 4, 'temp_max' => 12]);

        $result = $this->validator->validateRequest('POST', '/weather', [], [
            'current' => ['icon' => 'w_rain', 'temp' => 9],
            'forecast' => $forecast,
        ]);

        self::assertFalse($result->valid);
        self::assertNotNull($result->errorMessage);
    }

    public function testGaugeScenarioPayloadValidatesAgainstSpec(): void
    {
        $gauge = $this->catalog->findById('gauge-claude', true);
        self::assertNotNull($gauge);

        $result = $this->validator->validate($gauge);

        self::assertTrue($result->valid, $result->errorMessage ?? '');
        self::assertNull($result->errorMessage);
    }

    public function testSingleZoneCustomAppScenarioPayloadValidatesAgainstSpec(): void
    {
        $singleZoneCustomApp = $this->catalog->findById('custom-app-demo', true);
        self::assertNotNull($singleZoneCustomApp);

        $result = $this->validator->validate($singleZoneCustomApp);

        self::assertTrue($result->valid, $result->errorMessage ?? '');
        self::assertNull($result->errorMessage);
    }

    public function testMultiZoneCustomAppScenarioPayloadValidatesAgainstSpec(): void
    {
        $multiZoneCustomApp = $this->catalog->findById('custom-app-multi-zone', true);
        self::assertNotNull($multiZoneCustomApp);

        $result = $this->validator->validate($multiZoneCustomApp);

        self::assertTrue($result->valid, $result->errorMessage ?? '');
        self::assertNull($result->errorMessage);
    }

    public function testValidateRequestAcceptsAGaugePayload(): void
    {
        $payload = GaugePayload::create(
            name: 'claude',
            rows: [
                GaugeRow::create(
                    label: '5h',
                    percent: 41,
                    info: '14:50',
                    value: '41%',
                    note: 'x1.2^',
                    barColor: Color::fromHexCode('#4CAF50'),
                    noteColor: Color::fromHexCode('#FFC107'),
                ),
            ],
            title: 'Claude',
            iconName: 'claude',
            staleAfterInSeconds: 2700,
            staleBehavior: StaleBehavior::Dim,
        );

        $result = $this->validator->validateRequest('POST', '/gauge', ['name' => 'claude'], $payload->toArray());

        self::assertTrue($result->valid, $result->errorMessage ?? '');
        self::assertNull($result->errorMessage);
    }

    public function testValidateRequestAcceptsASingleZoneCustomAppPayload(): void
    {
        $payload = CustomAppPayload::createSingleZone(
            name: 'demo',
            text: PolymorphicTextField::fromSegments(
                TextSegment::create('22.5', Color::fromHexCode('#FF8800')),
                TextSegment::create('C', Color::fromHexCode('#666666')),
            ),
            iconName: 'thermo',
            label: 'Salon',
            color: Color::fromHexCode('#FF8800'),
            displayDurationMilliseconds: 10000,
            staleAfterInSeconds: 3600,
            staleBehavior: StaleBehavior::Hide,
        );

        $result = $this->validator->validateRequest('POST', '/custom', ['name' => 'demo'], $payload->toArray());

        self::assertTrue($result->valid, $result->errorMessage ?? '');
        self::assertNull($result->errorMessage);
    }

    public function testValidateRequestAcceptsAMultiZoneCustomAppPayload(): void
    {
        $payload = CustomAppPayload::createMultiZone(
            name: 'rooms',
            zones: [
                Zone::create(
                    text: PolymorphicTextField::fromSegments(
                        TextSegment::create('22.5', Color::fromHexCode('#FF8800')),
                        TextSegment::create('C', Color::fromHexCode('#666666')),
                    ),
                    iconName: 'thermo',
                    label: 'Salon',
                    color: Color::fromHexCode('#FF8800'),
                ),
                Zone::create(text: '19.1C', iconName: 'thermo', label: 'Chambre', color: Color::fromHexCode('#00D4FF')),
                Zone::create(text: '58%', iconName: 'humidity', label: 'Humidite', color: Color::fromHexCode('#00D4FF')),
                Zone::create(text: '412ppm', iconName: 'co2', label: 'CO2', color: Color::fromHexCode('#4CAF50')),
            ],
            displayDurationMilliseconds: 10000,
        );

        $result = $this->validator->validateRequest('POST', '/custom', ['name' => 'rooms'], $payload->toArray());

        self::assertTrue($result->valid, $result->errorMessage ?? '');
        self::assertNull($result->errorMessage);
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('gaugeBodyOutsideTheSpecProvider')]
    public function testValidateRequestRejectsAGaugeBodyOutsideTheSpec(array $body): void
    {
        $result = $this->validator->validateRequest('POST', '/gauge', ['name' => 'claude'], $body);

        self::assertFalse($result->valid);
        self::assertNotNull($result->errorMessage);
    }
}
