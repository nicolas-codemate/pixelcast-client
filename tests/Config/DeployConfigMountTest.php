<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A bind mount of a single file is pinned to the inode it was created with, and editors save
 * pixelcast.yaml by renaming a temporary file over it. Mounting the file itself would therefore
 * leave the container reading the version from before the edit, with nothing for the reload to pick
 * up.
 */
final class DeployConfigMountTest extends TestCase
{
    private const string DEPLOY_COMPOSE_FILE = 'deploy/compose.yaml';
    private const string DEPLOY_ENVIRONMENT_FILE = 'deploy/pixelcast.env.dist';
    private const string CONFIG_FILE_VARIABLE = 'PIXELCAST_CONFIG_FILE';

    public function testTheConfigurationIsMountedAsADirectoryTheEnvironmentFilePointsInto(): void
    {
        $mountModesByTarget = [];

        foreach ($this->phpServiceVolumeDeclarations() as $volumeDeclaration) {
            [$mountSource, $mountTarget, $mountMode] = array_pad(explode(':', $volumeDeclaration), 3, '');

            self::assertFalse(
                str_ends_with($mountSource, '.yaml') || str_ends_with($mountSource, '.yml'),
                \sprintf('Mount "%s" of %s mounts a YAML file: mount the directory holding it instead.', $volumeDeclaration, self::DEPLOY_COMPOSE_FILE),
            );

            $mountModesByTarget[$mountTarget] = $mountMode;
        }

        $mountedConfigDirectory = \dirname($this->configuredConfigFilePath());

        self::assertArrayHasKey(
            $mountedConfigDirectory,
            $mountModesByTarget,
            \sprintf('%s sets %s inside "%s", which %s mounts nowhere.', self::DEPLOY_ENVIRONMENT_FILE, self::CONFIG_FILE_VARIABLE, $mountedConfigDirectory, self::DEPLOY_COMPOSE_FILE),
        );
        self::assertSame(
            'ro',
            $mountModesByTarget[$mountedConfigDirectory],
            \sprintf('The mount of "%s" declared by %s must be read-only: the container never writes the configuration.', $mountedConfigDirectory, self::DEPLOY_COMPOSE_FILE),
        );
    }

    /**
     * @return list<string>
     */
    private function phpServiceVolumeDeclarations(): array
    {
        $composeTree = Yaml::parseFile(SyncsConfigLoaderFactory::projectFilePath(self::DEPLOY_COMPOSE_FILE));
        self::assertIsArray($composeTree);

        $declaredServices = $composeTree['services'] ?? null;
        self::assertIsArray($declaredServices);

        $phpService = $declaredServices['php'] ?? null;
        self::assertIsArray($phpService);

        $volumeDeclarations = $phpService['volumes'] ?? null;
        self::assertIsArray($volumeDeclarations);
        self::assertNotEmpty($volumeDeclarations);

        $declaredMounts = [];
        foreach ($volumeDeclarations as $volumeDeclaration) {
            self::assertIsString($volumeDeclaration);

            $declaredMounts[] = $volumeDeclaration;
        }

        return $declaredMounts;
    }

    private function configuredConfigFilePath(): string
    {
        $environmentFileContent = file_get_contents(SyncsConfigLoaderFactory::projectFilePath(self::DEPLOY_ENVIRONMENT_FILE));
        self::assertIsString($environmentFileContent);

        $activeAssignmentPrefix = self::CONFIG_FILE_VARIABLE.'=';

        foreach (explode("\n", $environmentFileContent) as $environmentLine) {
            if (str_starts_with($environmentLine, $activeAssignmentPrefix)) {
                return trim(substr($environmentLine, \strlen($activeAssignmentPrefix)));
            }
        }

        self::fail(\sprintf('%s must set %s rather than leave it commented out: its built-in default sits outside the mounted directory.', self::DEPLOY_ENVIRONMENT_FILE, self::CONFIG_FILE_VARIABLE));
    }
}
