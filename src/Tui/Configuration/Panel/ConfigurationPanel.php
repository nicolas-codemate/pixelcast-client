<?php

declare(strict_types=1);

namespace App\Tui\Configuration\Panel;

use App\Config\Exception\PixelCastConfigException;
use App\Config\PixelCastConfig;
use App\Config\PixelCastConfigLoader;
use App\Config\PixelCastConfigWriter;
use App\Tui\Configuration\ConfigurationFieldValidator;
use App\Tui\Configuration\SaveOutcome;
use App\Tui\TerminalSafeText;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\SettingItem;
use Symfony\Component\Tui\Widget\SettingsListWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ConfigurationPanel
{
    private const string HEADER_TEXT = 'Configuration - [Enter] edit  [S] save  [Esc] back';
    private const string SEPARATOR_TEXT = '----------------------------------------------------------------';
    private const string FOOTER_HINT = '[S] Save  [Esc] Cancel';

    private const array FIELD_LABELS = [
        ConfigurationFieldValidator::FIELD_DEVICE_URL => 'Device URL',
        ConfigurationFieldValidator::FIELD_WEATHER_INTERVAL => 'Weather interval (s)',
        ConfigurationFieldValidator::FIELD_TRACKER_INTERVAL => 'Tracker interval (s)',
        ConfigurationFieldValidator::FIELD_TRACKED_ASSETS => 'Tracked assets',
        ConfigurationFieldValidator::FIELD_WEATHER_SOURCE => 'Weather source',
        ConfigurationFieldValidator::FIELD_TRACKER_SOURCE => 'Tracker source',
    ];

    private const array INT_FIELDS = [
        ConfigurationFieldValidator::FIELD_WEATHER_INTERVAL,
        ConfigurationFieldValidator::FIELD_TRACKER_INTERVAL,
    ];

    private readonly SettingsListWidget $settingsList;
    private readonly TextWidget $statusLine;
    private readonly TextWidget $footerHint;
    private readonly ContainerWidget $container;

    /**
     * @var array<string, string>
     */
    private array $baselineValues;

    /**
     * @var array<string, string>
     */
    private array $workingValues;

    private bool $lastReportedUnsavedFlag = false;

    /**
     * @var list<callable(bool): void>
     */
    private array $unsavedChangesListeners = [];

    public function __construct(
        private readonly PixelCastConfigLoader $loader,
        private readonly PixelCastConfigWriter $writer,
        private readonly ConfigurationFieldValidator $validator,
    ) {
        $loaded = $this->loadInitialValues();
        $this->baselineValues = $loaded['baseline'];
        $this->workingValues = $loaded['working'];

        $this->settingsList = new SettingsListWidget(
            items: $this->buildSettingItems(),
            maxVisible: \count(self::FIELD_LABELS),
        );
        $this->statusLine = new TextWidget($loaded['initialStatus']);
        $this->footerHint = new TextWidget(self::FOOTER_HINT);

        $this->container = new ContainerWidget();
        $this->container->expandVertically(true);
        $this->container->add(new TextWidget(self::HEADER_TEXT));
        $this->container->add(new TextWidget(self::SEPARATOR_TEXT));
        $this->container->add($this->settingsList);
        $this->container->add($this->statusLine);
        $this->container->add($this->footerHint);

        $this->lastReportedUnsavedFlag = $this->hasUnsavedChanges();
    }

    public function widget(): ContainerWidget
    {
        return $this->container;
    }

    public function selectListWidget(): SettingsListWidget
    {
        return $this->settingsList;
    }

    public function hasUnsavedChanges(): bool
    {
        return $this->workingValues !== $this->baselineValues;
    }

    public function isEditingField(): bool
    {
        return [] !== $this->settingsList->all();
    }

    public function currentDeviceUrl(): string
    {
        return $this->workingValues[ConfigurationFieldValidator::FIELD_DEVICE_URL] ?? '';
    }

    public function onUnsavedChangesChanged(callable $listener): void
    {
        $this->unsavedChangesListeners[] = $listener;
    }

    public function applyFieldEdit(string $fieldId, string $rawValue): ?string
    {
        $error = $this->validator->validate($fieldId, $rawValue);
        if (null !== $error) {
            $this->statusLine->setText(TerminalSafeText::stripControlBytes($error));

            return $error;
        }

        $normalizedValue = $this->normalizeFieldValue($fieldId, $rawValue);
        $this->workingValues[$fieldId] = $normalizedValue;
        $this->settingsList->updateValue($fieldId, $normalizedValue);
        $this->statusLine->setText('');
        $this->notifyUnsavedChangesIfFlipped();

        return null;
    }

    public function commitSave(): SaveOutcome
    {
        if ($this->isEditingField()) {
            $this->statusLine->setText('Finish editing the current field (Enter or Esc) before saving.');

            return SaveOutcome::ValidationFailed;
        }

        foreach ($this->workingValues as $fieldId => $rawValue) {
            $error = $this->validator->validate($fieldId, $rawValue);
            if (null !== $error) {
                $this->statusLine->setText(TerminalSafeText::stripControlBytes($error));

                return SaveOutcome::ValidationFailed;
            }
        }

        try {
            $config = PixelCastConfig::fromArray($this->workingValuesAsRawMap());
        } catch (PixelCastConfigException $configError) {
            $this->statusLine->setText(TerminalSafeText::stripControlBytes($configError->getMessage()));

            return SaveOutcome::ValidationFailed;
        }

        try {
            $this->writer->save($config);
        } catch (PixelCastConfigException $writeError) {
            $this->statusLine->setText(TerminalSafeText::stripControlBytes($writeError->getMessage()));

            return SaveOutcome::WriteFailed;
        }

        $this->baselineValues = $this->workingValues;
        $this->statusLine->setText('Saved to '.TerminalSafeText::stripControlBytes($this->loader->filePath()));
        $this->notifyUnsavedChangesIfFlipped();

        return SaveOutcome::Saved;
    }

    public function discardChanges(): void
    {
        $this->workingValues = $this->baselineValues;
        $this->statusLine->setText('');
        foreach ($this->workingValues as $fieldId => $value) {
            $this->settingsList->updateValue($fieldId, $value);
        }
        $this->notifyUnsavedChangesIfFlipped();
    }

    public function statusLineText(): string
    {
        return $this->statusLine->getText();
    }

    /**
     * @return array{baseline: array<string, string>, working: array<string, string>, initialStatus: string}
     */
    private function loadInitialValues(): array
    {
        try {
            $config = $this->loader->load();
            $baseline = $this->configToWorkingMap($config);

            return [
                'baseline' => $baseline,
                'working' => $baseline,
                'initialStatus' => '',
            ];
        } catch (PixelCastConfigException) {
            $distMap = $this->readDistMap();

            $hasDistDefaults = '' !== ($distMap[ConfigurationFieldValidator::FIELD_DEVICE_URL] ?? '');

            return [
                'baseline' => [],
                'working' => $distMap,
                'initialStatus' => $hasDistDefaults
                    ? 'Loaded defaults from pixelcast.yaml.dist. Press [S] to create the file.'
                    : 'No configuration file found. Press [S] to create one.',
            ];
        }
    }

    /**
     * @return array<string, string>
     */
    private function readDistMap(): array
    {
        $distPath = $this->loader->filePath().'.dist';

        $emptyMap = array_fill_keys(array_keys(self::FIELD_LABELS), '');

        if (!is_file($distPath)) {
            return $emptyMap;
        }

        try {
            $parsed = Yaml::parseFile($distPath);
        } catch (ParseException) {
            return $emptyMap;
        }

        if (!\is_array($parsed)) {
            return $emptyMap;
        }

        $result = $emptyMap;
        foreach ($parsed as $key => $value) {
            if (\is_string($key) && \array_key_exists($key, $result)) {
                $result[$key] = $this->scalarToString($value);
            }
        }

        return $result;
    }

    private function scalarToString(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function workingValuesAsRawMap(): array
    {
        $raw = [];
        foreach ($this->workingValues as $fieldId => $value) {
            $raw[$fieldId] = \in_array($fieldId, self::INT_FIELDS, true) ? (int) $value : $value;
        }

        return $raw;
    }

    /**
     * @return array<string, string>
     */
    private function configToWorkingMap(PixelCastConfig $config): array
    {
        return [
            ConfigurationFieldValidator::FIELD_DEVICE_URL => $config->deviceUrl,
            ConfigurationFieldValidator::FIELD_WEATHER_INTERVAL => (string) $config->weatherInterval,
            ConfigurationFieldValidator::FIELD_TRACKER_INTERVAL => (string) $config->trackerInterval,
            ConfigurationFieldValidator::FIELD_TRACKED_ASSETS => implode(', ', $config->trackedAssets),
            ConfigurationFieldValidator::FIELD_WEATHER_SOURCE => $config->weatherSource,
            ConfigurationFieldValidator::FIELD_TRACKER_SOURCE => $config->trackerSource,
        ];
    }

    /**
     * @return list<SettingItem>
     */
    private function buildSettingItems(): array
    {
        $items = [];
        foreach (self::FIELD_LABELS as $fieldId => $label) {
            $items[] = new SettingItem(
                id: $fieldId,
                label: $label,
                currentValue: $this->workingValues[$fieldId],
                submenu: $this->buildInputSubmenuFactory($fieldId),
            );
        }

        return $items;
    }

    private function buildInputSubmenuFactory(string $fieldId): \Closure
    {
        return function (string $currentValue, callable $onDone) use ($fieldId): InputWidget {
            $input = new InputWidget();
            $input->setPrompt(self::FIELD_LABELS[$fieldId].': ');
            $input->setValue($currentValue);

            $input->onSubmit(function ($event) use ($fieldId, $onDone, $input): void {
                $submittedValue = $event->getValue();
                $error = $this->applyFieldEdit($fieldId, $submittedValue);
                if (null !== $error) {
                    $input->setValue($submittedValue);

                    return;
                }

                $onDone($this->workingValues[$fieldId]);
            });

            $input->onCancel(static function () use ($onDone): void {
                $onDone(null);
            });

            return $input;
        };
    }

    private function normalizeFieldValue(string $fieldId, string $rawValue): string
    {
        return match ($fieldId) {
            ConfigurationFieldValidator::FIELD_WEATHER_INTERVAL,
            ConfigurationFieldValidator::FIELD_TRACKER_INTERVAL => (string) (int) trim($rawValue),
            ConfigurationFieldValidator::FIELD_TRACKED_ASSETS => $this->normalizeTrackedAssets($rawValue),
            default => trim($rawValue),
        };
    }

    private function normalizeTrackedAssets(string $rawValue): string
    {
        $cleaned = [];
        foreach (explode(',', $rawValue) as $token) {
            $trimmed = trim($token);
            if ('' !== $trimmed) {
                $cleaned[] = $trimmed;
            }
        }

        return implode(', ', $cleaned);
    }

    private function notifyUnsavedChangesIfFlipped(): void
    {
        $currentFlag = $this->hasUnsavedChanges();
        if ($currentFlag === $this->lastReportedUnsavedFlag) {
            return;
        }

        $this->lastReportedUnsavedFlag = $currentFlag;
        foreach ($this->unsavedChangesListeners as $listener) {
            $listener($currentFlag);
        }
    }
}
