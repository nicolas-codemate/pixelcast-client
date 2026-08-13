<?php

declare(strict_types=1);

namespace App\Scenario;

use App\Provider\Claude\ClaudeUsageColors;

final class ScenarioCatalog
{
    private const string SIMULATOR_ONLY_RESET_ID = 'reset-simulator';

    /**
     * @return list<ScenarioDefinition>
     */
    public function all(bool $includeSimulatorOnly): array
    {
        $scenarios = self::buildScenarios();

        if (!$includeSimulatorOnly) {
            return array_values(array_filter(
                $scenarios,
                static fn (ScenarioDefinition $scenario): bool => self::SIMULATOR_ONLY_RESET_ID !== $scenario->id,
            ));
        }

        return $scenarios;
    }

    public function findById(string $id, bool $includeSimulatorOnly): ?ScenarioDefinition
    {
        foreach ($this->all($includeSimulatorOnly) as $scenario) {
            if ($scenario->id === $id) {
                return $scenario;
            }
        }

        return null;
    }

    /**
     * @return list<ScenarioDefinition>
     */
    private static function buildScenarios(): array
    {
        return [
            new ScenarioDefinition(
                id: 'weather',
                label: 'Weather',
                description: 'current conditions + hourly window + forecast',
                httpMethod: 'POST',
                path: '/weather',
                body: [
                    'current' => [
                        'icon' => 'w_clear_day',
                        'temp' => 22,
                        'temp_min' => 16,
                        'temp_max' => 29,
                        'humidity' => 50,
                    ],
                    'forecast' => [
                        [
                            'day' => 'MON',
                            'icon' => 'w_partly_day',
                            'temp_min' => 14,
                            'temp_max' => 23,
                        ],
                    ],
                    'today' => [
                        'hours' => [
                            ['h' => 15, 'temp' => 22, 'pop' => 0, 'precip' => 0],
                            ['h' => 16, 'temp' => 23, 'pop' => 10, 'precip' => 2],
                            ['h' => 17, 'temp' => 23, 'pop' => 40, 'precip' => 8],
                            ['h' => 18, 'temp' => 21, 'pop' => 80, 'precip' => 25],
                            ['h' => 19, 'temp' => 19, 'pop' => 30, 'precip' => 4],
                            ['h' => 20, 'temp' => 18, 'pop' => 10, 'precip' => 0],
                        ],
                    ],
                ],
            ),
            new ScenarioDefinition(
                id: 'tracker-btc',
                label: 'Tracker - BTC (crypto)',
                description: 'crypto tracker, USD price + sparkline',
                httpMethod: 'POST',
                path: '/tracker',
                queryParams: ['name' => 'BTC'],
                body: [
                    'symbol' => 'BTC',
                    'icon' => 'bitcoin',
                    'currency' => 'USD',
                    'value' => 98452.30,
                    'change' => 2.14,
                    'sparkline' => [92100, 89300, 93200, 91800, 95400, 94100, 97600, 96200, 98452],
                    'symbolColor' => '#FF8800',
                    'sparklineColor' => '#00D4FF',
                ],
            ),
            new ScenarioDefinition(
                id: 'tracker-aapl',
                label: 'Tracker - AAPL (stock)',
                description: 'stock tracker, USD price',
                httpMethod: 'POST',
                path: '/tracker',
                queryParams: ['name' => 'AAPL'],
                body: [
                    'symbol' => 'AAPL',
                    'icon' => 'stock',
                    'currency' => 'USD',
                    'value' => 213.40,
                    'change' => -0.4,
                ],
            ),
            new ScenarioDefinition(
                id: 'tracker-spy',
                label: 'Tracker - SPY (ETF/index)',
                description: 'ETF tracker, USD price',
                httpMethod: 'POST',
                path: '/tracker',
                queryParams: ['name' => 'SPY'],
                body: [
                    'symbol' => 'SPY',
                    'currency' => 'USD',
                    'value' => 528.10,
                    'change' => 0.1,
                ],
            ),
            new ScenarioDefinition(
                id: 'gauge-claude',
                label: 'Gauge - Claude subscription limits',
                description: 'four bounded rows over two pages, with pace notes',
                httpMethod: 'POST',
                path: '/gauge',
                queryParams: ['name' => 'claude'],
                body: [
                    'title' => [
                        ['t' => 'Claude', 'c' => ClaudeUsageColors::TITLE_HEX_CODE],
                    ],
                    'icon' => 'claude',
                    'duration' => 10000,
                    'staleAfter' => 2700,
                    'staleBehavior' => 'dim',
                    'rows' => [
                        [
                            'label' => [
                                ['t' => '5h', 'c' => ClaudeUsageColors::SESSION_LABEL_HEX_CODE],
                            ],
                            'info' => '14:50',
                            'value' => '41%',
                            'percent' => 41,
                            'color' => ClaudeUsageColors::GREEN_HEX_CODE,
                            'note' => 'x1.2^',
                            'noteColor' => ClaudeUsageColors::YELLOW_HEX_CODE,
                        ],
                        [
                            'label' => [
                                ['t' => '7j', 'c' => ClaudeUsageColors::WEEKLY_LABEL_HEX_CODE],
                            ],
                            'info' => '15/08 17h',
                            'value' => '28%',
                            'percent' => 28,
                            'color' => ClaudeUsageColors::GREEN_HEX_CODE,
                            'note' => 'x1.1>',
                            'noteColor' => ClaudeUsageColors::GREEN_HEX_CODE,
                        ],
                        [
                            'label' => [
                                ['t' => 'fable', 'c' => ClaudeUsageColors::FABLE_LABEL_HEX_CODE],
                            ],
                            'value' => '3%',
                            'percent' => 3,
                            'color' => ClaudeUsageColors::GREEN_HEX_CODE,
                        ],
                        [
                            'label' => [
                                ['t' => 'credits', 'c' => ClaudeUsageColors::CREDITS_LABEL_HEX_CODE],
                            ],
                            'value' => '1%',
                            'percent' => 1,
                            'color' => ClaudeUsageColors::GREEN_HEX_CODE,
                        ],
                    ],
                ],
            ),
            new ScenarioDefinition(
                id: 'notification-standard',
                label: 'Notification - standard',
                description: 'inline message, 5 s default duration',
                httpMethod: 'POST',
                path: '/notify',
                body: [
                    'text' => 'New message!',
                    'icon' => 'mail',
                    'color' => '#0096FF',
                    'duration' => 5000,
                ],
            ),
            new ScenarioDefinition(
                id: 'notification-urgent',
                label: 'Notification - urgent',
                description: 'urgent alert, persists until acked',
                httpMethod: 'POST',
                path: '/notify',
                body: [
                    'text' => 'Alert!',
                    'icon' => 'warning',
                    'color' => '#FF0000',
                    'urgent' => true,
                    'hold' => true,
                ],
            ),
            new ScenarioDefinition(
                id: 'custom-app-demo',
                label: 'Custom App - demo app',
                description: 'single named demo custom app',
                httpMethod: 'POST',
                path: '/custom',
                queryParams: ['name' => 'demo'],
                body: [
                    'text' => 'Hello World',
                    'icon' => 'smiley',
                    'color' => '#FF8800',
                    'duration' => 10000,
                ],
            ),
            new ScenarioDefinition(
                id: 'custom-app-multi-zone',
                label: 'Custom App - four-zone dashboard',
                description: 'quad grid, one icon and one reading per zone',
                httpMethod: 'POST',
                path: '/custom',
                queryParams: ['name' => 'rooms'],
                body: [
                    'zones' => [
                        ['text' => '22.5C', 'icon' => 'thermo', 'label' => 'Salon', 'color' => '#FF8800'],
                        ['text' => '19.1C', 'icon' => 'thermo', 'label' => 'Chambre', 'color' => '#00D4FF'],
                        ['text' => '58%', 'icon' => 'humidity', 'label' => 'Humidite', 'color' => '#00D4FF'],
                        ['text' => '412ppm', 'icon' => 'co2', 'label' => 'CO2', 'color' => '#4CAF50'],
                    ],
                    'duration' => 10000,
                ],
            ),
            new ScenarioDefinition(
                id: 'indicator-slot-1',
                label: 'Indicator - slot 1 (green)',
                description: 'solid green on slot 1',
                httpMethod: 'POST',
                path: '/indicator1',
                body: [
                    'mode' => 'solid',
                    'color' => '#00FF00',
                ],
            ),
            new ScenarioDefinition(
                id: 'indicator-slot-2',
                label: 'Indicator - slot 2 (yellow)',
                description: 'solid yellow on slot 2',
                httpMethod: 'POST',
                path: '/indicator2',
                body: [
                    'mode' => 'solid',
                    'color' => '#FFFF00',
                ],
            ),
            new ScenarioDefinition(
                id: 'indicator-slot-3',
                label: 'Indicator - slot 3 (red)',
                description: 'blinking red on slot 3',
                httpMethod: 'POST',
                path: '/indicator3',
                body: [
                    'mode' => 'blink',
                    'color' => '#FF0000',
                    'blinkInterval' => 500,
                ],
            ),
            new ScenarioDefinition(
                id: 'brightness',
                label: 'Brightness - set to 200',
                description: 'set device brightness to 200/255',
                httpMethod: 'POST',
                path: '/brightness',
                body: ['brightness' => 200],
            ),
            new ScenarioDefinition(
                id: 'settings-patch',
                label: 'Settings - patch defaultDuration=5000',
                description: 'patch one settings key',
                httpMethod: 'POST',
                path: '/settings',
                body: ['defaultDuration' => 5000],
            ),
            new ScenarioDefinition(
                id: 'icon-register',
                label: 'Icon - register sun_icon (LaMetric 2867)',
                description: 'register a LaMetric icon by id',
                httpMethod: 'POST',
                path: '/icons/lametric',
                body: ['id' => 2867, 'name' => 'sun_icon'],
            ),
            new ScenarioDefinition(
                id: 'reset-simulator',
                label: 'Reset simulator state',
                description: 'dev-only: POST /__reset (path is outside the OpenAPI spec)',
                httpMethod: 'POST',
                path: '/__reset',
                body: null,
            ),
        ];
    }
}
