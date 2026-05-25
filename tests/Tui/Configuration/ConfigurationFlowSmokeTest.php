<?php

declare(strict_types=1);

namespace App\Tests\Tui\Configuration;

use App\Command\TuiView;
use App\Config\PixelCastConfigLoader;
use App\Config\PixelCastConfigWriter;
use App\Tui\Configuration\ConfigurationFieldValidator;
use App\Tui\Configuration\Panel\ConfigurationPanel;
use App\Tui\Configuration\SaveOutcome;
use App\Tui\Menu\TuiMenuFactory;
use App\Tui\Menu\TuiMenuItem;
use App\Tui\TuiMode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class ConfigurationFlowSmokeTest extends KernelTestCase
{
    private string $temporaryConfigPath;

    protected function setUp(): void
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'pixelcast-');
        self::assertNotFalse($temporaryPath);
        $this->temporaryConfigPath = $temporaryPath;
    }

    protected function tearDown(): void
    {
        foreach ([$this->temporaryConfigPath, $this->temporaryConfigPath.'.tmp'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function testProdMenuExposesConfigurationItemWithExpectedShortcut(): void
    {
        $items = TuiMenuFactory::buildForMode(TuiMode::Prod);

        $configurationItem = null;
        foreach ($items as $item) {
            if ('configuration' === $item->value) {
                $configurationItem = $item;

                break;
            }
        }

        self::assertInstanceOf(TuiMenuItem::class, $configurationItem);
        self::assertSame('2', $configurationItem->shortcut);
        self::assertSame('Configuration', $configurationItem->label);
    }

    public function testTuiViewConfigurationCaseExists(): void
    {
        self::assertSame('configuration', TuiView::Configuration->value);
    }

    public function testEndToEndSaveRoundTripPersistsValueAndPreservesLeadingComment(): void
    {
        $distPath = $this->projectRoot().'/pixelcast.yaml.dist';
        self::assertTrue(is_file($distPath), 'pixelcast.yaml.dist must exist at the project root.');

        $distContent = file_get_contents($distPath);
        self::assertIsString($distContent);

        $bytesWritten = file_put_contents($this->temporaryConfigPath, $distContent);
        self::assertNotFalse($bytesWritten);

        $loader = new PixelCastConfigLoader($this->temporaryConfigPath);
        $writer = new PixelCastConfigWriter($this->temporaryConfigPath);
        $validator = new ConfigurationFieldValidator();

        $panel = new ConfigurationPanel($loader, $writer, $validator);

        self::assertFalse($panel->hasUnsavedChanges());

        $error = $panel->applyFieldEdit(ConfigurationFieldValidator::FIELD_WEATHER_INTERVAL, '900');
        self::assertNull($error);
        self::assertTrue($panel->hasUnsavedChanges());

        $outcome = $panel->commitSave();
        self::assertSame(SaveOutcome::Saved, $outcome);
        self::assertFalse($panel->hasUnsavedChanges());

        $reloadedConfig = new PixelCastConfigLoader($this->temporaryConfigPath)->load();
        self::assertSame(900, $reloadedConfig->weatherInterval);

        $distLeadingComment = $this->firstNonBlankLine($distContent);
        $rewrittenContent = file_get_contents($this->temporaryConfigPath);
        self::assertIsString($rewrittenContent);
        $rewrittenLeadingComment = $this->firstNonBlankLine($rewrittenContent);

        self::assertNotSame('', $distLeadingComment);
        self::assertStringStartsWith('#', $distLeadingComment);
        self::assertSame($distLeadingComment, $rewrittenLeadingComment);
    }

    public function testRuntimeConfigPathIsGitignored(): void
    {
        $gitBinary = new ExecutableFinder()->find('git');
        if (null === $gitBinary) {
            self::markTestSkipped('git is not available on this host.');
        }

        $projectRoot = $this->projectRoot();

        $statusProcess = new Process([$gitBinary, 'rev-parse', '--is-inside-work-tree'], $projectRoot);
        $statusProcess->run();
        if (0 !== $statusProcess->getExitCode()) {
            self::markTestSkipped(\sprintf('git refused to operate in "%s": %s', $projectRoot, trim($statusProcess->getErrorOutput())));
        }

        $process = new Process([$gitBinary, 'check-ignore', '--quiet', 'pixelcast.yaml'], $projectRoot);
        $process->run();

        self::assertSame(
            0,
            $process->getExitCode(),
            \sprintf('pixelcast.yaml should be gitignored. git output: %s', $process->getErrorOutput()),
        );
    }

    private function projectRoot(): string
    {
        self::bootKernel();

        /** @var string $projectDir */
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');

        return $projectDir;
    }

    private function firstNonBlankLine(string $content): string
    {
        $lines = preg_split('/\r?\n/', $content);
        if (false === $lines) {
            return '';
        }

        foreach ($lines as $line) {
            if ('' !== trim($line)) {
                return $line;
            }
        }

        return '';
    }
}
