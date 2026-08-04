<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The host runs unattended for months, and nothing else caps the growth of its logs.
 */
final class DeployComposeLoggingTest extends TestCase
{
    private const string DEPLOY_COMPOSE_FILE = 'deploy/compose.yaml';

    public function testEveryServiceBoundsItsDockerLogs(): void
    {
        $composeTree = Yaml::parseFile(SyncsConfigLoaderFactory::projectFilePath(self::DEPLOY_COMPOSE_FILE));
        self::assertIsArray($composeTree);

        $declaredServices = $composeTree['services'];
        self::assertIsArray($declaredServices);
        self::assertNotEmpty($declaredServices);

        foreach ($declaredServices as $serviceName => $serviceDefinition) {
            $failureMessage = \sprintf('Service "%s" of %s must bound its logs.', (string) $serviceName, self::DEPLOY_COMPOSE_FILE);

            self::assertIsArray($serviceDefinition, $failureMessage);

            $loggingDeclaration = $serviceDefinition['logging'] ?? null;
            self::assertIsArray($loggingDeclaration, $failureMessage);
            self::assertSame('json-file', $loggingDeclaration['driver'] ?? null, $failureMessage);

            $rotationOptions = $loggingDeclaration['options'] ?? null;
            self::assertIsArray($rotationOptions, $failureMessage);
            self::assertNotEmpty($rotationOptions['max-size'] ?? null, $failureMessage);

            $keptFileCount = $rotationOptions['max-file'] ?? null;
            self::assertIsNumeric($keptFileCount, $failureMessage);
            self::assertGreaterThanOrEqual(2, (int) $keptFileCount, $failureMessage);
        }
    }
}
