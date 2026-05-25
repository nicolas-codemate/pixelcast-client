<?php

declare(strict_types=1);

namespace App\Tests\Tui\Scenarios\Validation;

use App\Tui\Scenarios\ScenarioCatalog;
use App\Tui\Scenarios\ScenarioDefinition;
use App\Tui\Scenarios\Validation\OutboundOpenApiValidatorFactory;
use App\Tui\Scenarios\Validation\OutboundPayloadValidator;
use App\Tui\TuiMode;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OutboundPayloadValidatorTest extends KernelTestCase
{
    private const string TEST_DEVICE_BASE_URL = 'http://simulator:8080';

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
        $weather = $this->catalog->findById('weather', TuiMode::Dev);
        self::assertNotNull($weather);

        $result = $this->validator->validate($weather);

        self::assertTrue($result->valid, $result->errorMessage ?? '');
        self::assertNull($result->errorMessage);
    }

    public function testTrackerScenarioWithQueryStringValidatesAgainstSpec(): void
    {
        $tracker = $this->catalog->findById('tracker-btc', TuiMode::Dev);
        self::assertNotNull($tracker);

        $result = $this->validator->validate($tracker);

        self::assertTrue($result->valid, $result->errorMessage ?? '');
    }

    public function testIconRegisterScenarioPayloadValidatesAgainstSpec(): void
    {
        $iconRegister = $this->catalog->findById('icon-register', TuiMode::Dev);
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
}
