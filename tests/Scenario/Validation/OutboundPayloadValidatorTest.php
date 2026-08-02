<?php

declare(strict_types=1);

namespace App\Tests\Scenario\Validation;

use App\Scenario\ScenarioCatalog;
use App\Scenario\ScenarioDefinition;
use App\Scenario\Validation\OutboundOpenApiValidatorFactory;
use App\Scenario\Validation\OutboundPayloadValidator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OutboundPayloadValidatorTest extends KernelTestCase
{
    private const string TEST_DEVICE_BASE_URL = 'http://simulator:8080/api';

    private OutboundPayloadValidator $validator;
    private ScenarioCatalog $catalog;

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
        $forecast = [];

        for ($dayIndex = 0; $dayIndex < 8; ++$dayIndex) {
            $forecast[] = ['day' => 'LUN', 'icon' => 'w_rain', 'temp_min' => 4, 'temp_max' => 12];
        }

        $result = $this->validator->validateRequest('POST', '/weather', [], [
            'current' => ['icon' => 'w_rain', 'temp' => 9],
            'forecast' => $forecast,
        ]);

        self::assertFalse($result->valid);
        self::assertNotNull($result->errorMessage);
    }
}
