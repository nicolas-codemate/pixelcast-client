<?php

declare(strict_types=1);

namespace App\Tests\Tui\Configuration\Panel;

use App\Config\PixelCastConfigLoader;
use App\Config\PixelCastConfigWriter;
use App\Tui\Configuration\ConfigurationFieldValidator;
use App\Tui\Configuration\Panel\ConfigurationPanel;
use App\Tui\Configuration\SaveOutcome;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SettingItem;

final class ConfigurationPanelTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__.'/../../../Config/Fixtures/with-comments.yaml';

    private string $temporaryConfigPath;

    protected function setUp(): void
    {
        $this->temporaryConfigPath = \sprintf(
            '%s/configuration-panel-%s.yaml',
            sys_get_temp_dir(),
            bin2hex(random_bytes(8)),
        );
    }

    protected function tearDown(): void
    {
        foreach ([$this->temporaryConfigPath, $this->temporaryConfigPath.'.tmp'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function testWidgetIsContainerWidget(): void
    {
        $panel = $this->buildPanelWithFixture();

        self::assertInstanceOf(ContainerWidget::class, $panel->widget());
    }

    public function testInitialStateMatchesLoadedConfig(): void
    {
        $this->copyFixture();
        $expectedConfig = new PixelCastConfigLoader($this->temporaryConfigPath)->load();
        $panel = $this->buildPanel();

        self::assertSame($expectedConfig->deviceUrl, $panel->currentDeviceUrl());
        self::assertFalse($panel->hasUnsavedChanges());
    }

    public function testEditingFieldFlipsUnsavedFlag(): void
    {
        $panel = $this->buildPanelWithFixture();

        $error = $panel->applyFieldEdit(ConfigurationFieldValidator::FIELD_WEATHER_INTERVAL, '600');

        self::assertNull($error);
        self::assertTrue($panel->hasUnsavedChanges());
    }

    public function testDiscardChangesRestoresBaseline(): void
    {
        $panel = $this->buildPanelWithFixture();

        $panel->applyFieldEdit(ConfigurationFieldValidator::FIELD_WEATHER_INTERVAL, '900');
        self::assertTrue($panel->hasUnsavedChanges());

        $panel->discardChanges();

        self::assertFalse($panel->hasUnsavedChanges());
    }

    public function testCommitSavePersistsToFile(): void
    {
        $panel = $this->buildPanelWithFixture();

        $panel->applyFieldEdit(ConfigurationFieldValidator::FIELD_WEATHER_INTERVAL, '600');

        $outcome = $panel->commitSave();

        self::assertSame(SaveOutcome::Saved, $outcome);
        self::assertFalse($panel->hasUnsavedChanges());

        $reloaded = new PixelCastConfigLoader($this->temporaryConfigPath)->load();
        self::assertSame(600, $reloaded->weatherInterval);
    }

    public function testCommitSaveReportsValidationFailureWithoutWriting(): void
    {
        $panel = $this->buildPanelWithFixture();
        $originalContent = file_get_contents($this->temporaryConfigPath);
        self::assertIsString($originalContent);

        $error = $panel->applyFieldEdit(ConfigurationFieldValidator::FIELD_WEATHER_INTERVAL, 'not-a-number');

        self::assertNotNull($error);
        self::assertFalse($panel->hasUnsavedChanges());
        self::assertSame($originalContent, file_get_contents($this->temporaryConfigPath));
        self::assertNotSame('', $panel->statusLineText());
    }

    public function testApplyFieldEditRejectsInvalidValue(): void
    {
        $panel = $this->buildPanelWithFixture();

        $error = $panel->applyFieldEdit(ConfigurationFieldValidator::FIELD_DEVICE_URL, 'not a url');

        self::assertNotNull($error);
        self::assertFalse($panel->hasUnsavedChanges());
        self::assertNotSame('', $panel->statusLineText());
    }

    public function testUnsavedChangesListenerFiresOnTransition(): void
    {
        $panel = $this->buildPanelWithFixture();

        $observed = [];
        $panel->onUnsavedChangesChanged(static function (bool $hasUnsaved) use (&$observed): void {
            $observed[] = $hasUnsaved;
        });

        $panel->applyFieldEdit(ConfigurationFieldValidator::FIELD_WEATHER_INTERVAL, '777');
        $panel->discardChanges();

        self::assertSame([true, false], $observed);
    }

    public function testBootstrapFromDistMarksPanelAsUnsaved(): void
    {
        $distPath = $this->temporaryConfigPath.'.dist';
        copy(self::FIXTURE_PATH, $distPath);

        try {
            $panel = $this->buildPanel();

            self::assertTrue($panel->hasUnsavedChanges());
            self::assertNotSame('', $panel->currentDeviceUrl());
        } finally {
            @unlink($distPath);
        }
    }

    public function testCommitSaveAfterBootstrapCreatesRuntimeFile(): void
    {
        $distPath = $this->temporaryConfigPath.'.dist';
        copy(self::FIXTURE_PATH, $distPath);

        try {
            $panel = $this->buildPanel();
            self::assertFalse(is_file($this->temporaryConfigPath));

            $outcome = $panel->commitSave();

            self::assertSame(SaveOutcome::Saved, $outcome);
            self::assertTrue(is_file($this->temporaryConfigPath));
            self::assertFalse($panel->hasUnsavedChanges());
        } finally {
            @unlink($distPath);
        }
    }

    public function testCommitSaveRejectsWhenEditingFieldIsOpen(): void
    {
        $panel = $this->buildPanelWithFixture();

        $this->openFieldEditor($panel, ConfigurationFieldValidator::FIELD_WEATHER_INTERVAL);

        $outcome = $panel->commitSave();

        self::assertSame(SaveOutcome::ValidationFailed, $outcome);
        self::assertTrue($panel->isEditingField());
        self::assertStringContainsString('Finish editing', $panel->statusLineText());
    }

    private function buildPanelWithFixture(): ConfigurationPanel
    {
        $this->copyFixture();

        return $this->buildPanel();
    }

    private function buildPanel(): ConfigurationPanel
    {
        $loader = new PixelCastConfigLoader($this->temporaryConfigPath);
        $writer = new PixelCastConfigWriter($this->temporaryConfigPath);
        $validator = new ConfigurationFieldValidator();

        return new ConfigurationPanel($loader, $writer, $validator);
    }

    private function copyFixture(): void
    {
        $content = file_get_contents(self::FIXTURE_PATH);
        self::assertNotFalse($content);
        $written = file_put_contents($this->temporaryConfigPath, $content);
        self::assertNotFalse($written);
    }

    private function openFieldEditor(ConfigurationPanel $panel, string $fieldId): void
    {
        $settingsList = $panel->selectListWidget();
        /** @var list<SettingItem> $items */
        $items = new \ReflectionProperty($settingsList, 'items')->getValue($settingsList);

        $targetIndex = null;
        foreach ($items as $index => $item) {
            if ($item->getId() === $fieldId) {
                $targetIndex = $index;
                break;
            }
        }
        self::assertNotNull($targetIndex, \sprintf('Setting item "%s" not found.', $fieldId));

        new \ReflectionProperty($settingsList, 'selectedIndex')->setValue($settingsList, $targetIndex);
        new \ReflectionMethod($settingsList, 'activateCurrentItem')->invoke($settingsList);
    }
}
