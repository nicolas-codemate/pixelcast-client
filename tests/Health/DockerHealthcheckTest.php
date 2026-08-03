<?php

declare(strict_types=1);

namespace App\Tests\Health;

use App\Command\HealthCommand;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The probed command is named twice, in the Dockerfile and in the AsCommand attribute.
 */
final class DockerHealthcheckTest extends TestCase
{
    private const string DOCKERFILE = 'Dockerfile';

    public function testTheImageDeclaresAHealthcheck(): void
    {
        self::assertStringContainsString('HEALTHCHECK ', self::dockerfileContents());
    }

    public function testTheHealthcheckRunsACommandTheApplicationRegisters(): void
    {
        self::assertSame(self::commandNameDeclaredByHealthCommand(), self::commandNameProbedByTheHealthcheck());
    }

    private static function commandNameProbedByTheHealthcheck(): string
    {
        // A Dockerfile directive spans as many lines as it ends with a backslash.
        $healthcheckDirectivePattern = '/^HEALTHCHECK\b(?:[^\n]*\\\\\n)*[^\n]*/m';

        if (1 !== preg_match($healthcheckDirectivePattern, self::dockerfileContents(), $directiveMatch)) {
            self::fail('The Dockerfile declares no HEALTHCHECK directive.');
        }

        if (1 !== preg_match('/CMD\s+(\[[^\]]*\])/', $directiveMatch[0], $probeMatch)) {
            self::fail('The HEALTHCHECK directive runs no command in exec form.');
        }

        $probedArguments = json_decode($probeMatch[1], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($probedArguments);

        $probedCommandName = end($probedArguments);
        self::assertIsString($probedCommandName);

        return $probedCommandName;
    }

    private static function commandNameDeclaredByHealthCommand(): string
    {
        $asCommandAttributes = new \ReflectionClass(HealthCommand::class)->getAttributes(AsCommand::class);
        self::assertCount(1, $asCommandAttributes);

        return $asCommandAttributes[0]->newInstance()->name;
    }

    private static function dockerfileContents(): string
    {
        $dockerfileContents = file_get_contents(SyncsConfigLoaderFactory::projectFilePath(self::DOCKERFILE));
        self::assertIsString($dockerfileContents);

        return $dockerfileContents;
    }
}
