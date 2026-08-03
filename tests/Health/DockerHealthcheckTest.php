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

    public function testTheHealthcheckProbesACommandTheApplicationRegisters(): void
    {
        $dockerfileContents = file_get_contents(SyncsConfigLoaderFactory::projectFilePath(self::DOCKERFILE));
        self::assertIsString($dockerfileContents);

        if (1 !== preg_match('/HEALTHCHECK\b.*?CMD\s+(\[[^\]]*\])/s', $dockerfileContents, $healthcheckMatch)) {
            self::fail('The Dockerfile declares no HEALTHCHECK running a command in exec form.');
        }

        $probedArguments = json_decode($healthcheckMatch[1], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($probedArguments);

        self::assertContains(self::commandNameDeclaredByHealthCommand(), $probedArguments);
    }

    private static function commandNameDeclaredByHealthCommand(): string
    {
        $asCommandAttributes = new \ReflectionClass(HealthCommand::class)->getAttributes(AsCommand::class);
        self::assertCount(1, $asCommandAttributes);

        return $asCommandAttributes[0]->newInstance()->name;
    }
}
