<?php

declare(strict_types=1);

namespace App\Provider\HomeAssistant;

use App\Client\CustomApp\CustomAppPayload;
use App\Client\CustomApp\Zone;
use App\Config\Sync\HomeAssistantSensorItem;
use App\Config\Sync\HomeAssistantSyncConfig;
use App\Config\SyncsConfigLoader;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the state of each configured entity from the Home Assistant REST API and draws them as
 * one custom app, one zone per sensor.
 *
 * Nothing here throws. A missing address or token, an unreachable instance and an entity
 * Home Assistant cannot read all end as a logged warning: an unreadable sensor keeps its zone
 * with a placeholder value, so the layout does not move from one cycle to the next, and only a
 * cycle where no sensor at all could be read leaves the screen as it stands.
 */
final readonly class HomeAssistantSensorProvider implements HomeAssistantSensorProviderInterface
{
    private const string CUSTOM_APP_NAME = 'homeassistant';
    private const string STATES_PATH = '/api/states/';
    private const string STATE_KEY = 'state';
    private const string ATTRIBUTES_KEY = 'attributes';
    private const string UNIT_KEY = 'unit_of_measurement';
    private const string URL_ENVIRONMENT_VARIABLE = 'PIXELCAST_HOMEASSISTANT_URL';
    private const string TOKEN_ENVIRONMENT_VARIABLE = 'PIXELCAST_HOMEASSISTANT_TOKEN';
    private const string UNREADABLE_VALUE_PLACEHOLDER = '--';
    private const float REQUEST_TIMEOUT_IN_SECONDS = 5.0;
    private const int VALUE_DECIMALS = 1;

    /**
     * The temperature units Home Assistant reports, shortened to the degree sign alone: the zone
     * of a four-sensor grid is 32 pixels wide, and the unit is the same on every zone anyway.
     */
    private const array TEMPERATURE_UNITS = ['°C', '°F'];
    private const string DEGREE_SIGN = '°';

    public function __construct(
        private HttpClientInterface $httpClient,
        private SyncsConfigLoader $configLoader,
        private LoggerInterface $logger,
        private ?string $baseUrl = null,
        private ?string $token = null,
    ) {
    }

    public function fetchSensorsCustomApp(): ?CustomAppPayload
    {
        if (null === $this->baseUrl || '' === $this->baseUrl) {
            $this->logger->warning('Home Assistant needs an address', [
                'environment_variable' => self::URL_ENVIRONMENT_VARIABLE,
            ]);

            return null;
        }

        if (null === $this->token || '' === $this->token) {
            $this->logger->warning('Home Assistant needs a long-lived access token', [
                'environment_variable' => self::TOKEN_ENVIRONMENT_VARIABLE,
            ]);

            return null;
        }

        $homeAssistantSyncGroup = $this->configLoader->load()->syncGroupOfType(HomeAssistantSyncConfig::class);

        $zones = [];
        $readableSensorCount = 0;
        foreach ($homeAssistantSyncGroup->items as $sensorItem) {
            $displayedValue = $this->readDisplayedValue($sensorItem->entityId);
            if (null !== $displayedValue) {
                ++$readableSensorCount;
            }

            $zones[] = self::buildZone($sensorItem, $displayedValue ?? self::UNREADABLE_VALUE_PLACEHOLDER);
        }

        if (0 === $readableSensorCount) {
            return null;
        }

        return $this->buildCustomApp($zones, $homeAssistantSyncGroup);
    }

    private function readDisplayedValue(string $entityId): ?string
    {
        try {
            /** @var array<array-key, mixed> $decodedResponse */
            $decodedResponse = $this->httpClient->request('GET', rtrim((string) $this->baseUrl, '/').self::STATES_PATH.rawurlencode($entityId), [
                'headers' => ['Authorization' => 'Bearer '.$this->token],
                'timeout' => self::REQUEST_TIMEOUT_IN_SECONDS,
            ])->toArray();
        } catch (HttpClientExceptionInterface $httpError) {
            $this->logger->warning('The Home Assistant state could not be read', [
                'entity' => $entityId,
                'error' => $httpError->getMessage(),
            ]);

            return null;
        }

        $state = $decodedResponse[self::STATE_KEY] ?? null;

        // Home Assistant reports "unavailable" or "unknown" when the sensor itself is out of
        // reach, a Netatmo module with a flat battery for instance.
        if (!\is_string($state) || !is_numeric($state)) {
            $this->logger->warning('The Home Assistant state carries no numeric value', [
                'entity' => $entityId,
                'state' => $state,
            ]);

            return null;
        }

        $attributes = $decodedResponse[self::ATTRIBUTES_KEY] ?? null;
        $unit = \is_array($attributes) && \is_string($attributes[self::UNIT_KEY] ?? null) ? $attributes[self::UNIT_KEY] : '';

        return (string) round((float) $state, self::VALUE_DECIMALS).self::displayedUnitOf($unit);
    }

    private static function displayedUnitOf(string $unit): string
    {
        return \in_array($unit, self::TEMPERATURE_UNITS, true) ? self::DEGREE_SIGN : $unit;
    }

    private static function buildZone(HomeAssistantSensorItem $sensorItem, string $displayedValue): Zone
    {
        return Zone::create(
            text: $displayedValue,
            iconName: $sensorItem->iconName,
            label: $sensorItem->label,
            color: $sensorItem->valueColor,
        );
    }

    /**
     * @param list<Zone> $zones
     */
    private function buildCustomApp(array $zones, HomeAssistantSyncConfig $homeAssistantSyncGroup): ?CustomAppPayload
    {
        $staleAfterInSeconds = $homeAssistantSyncGroup->staleDeclaration->staleAfterInSeconds;
        $staleBehavior = $homeAssistantSyncGroup->staleDeclaration->staleBehavior;

        try {
            if (1 === \count($zones)) {
                $singleZone = $zones[0];

                return CustomAppPayload::createSingleZone(
                    name: self::CUSTOM_APP_NAME,
                    text: $singleZone->text,
                    iconName: $singleZone->iconName,
                    label: $singleZone->label,
                    color: $singleZone->color,
                    staleAfterInSeconds: $staleAfterInSeconds,
                    staleBehavior: $staleBehavior,
                );
            }

            return CustomAppPayload::createMultiZone(
                name: self::CUSTOM_APP_NAME,
                zones: $zones,
                staleAfterInSeconds: $staleAfterInSeconds,
                staleBehavior: $staleBehavior,
            );
        } catch (\InvalidArgumentException $invalidCustomApp) {
            $this->logger->warning('The Home Assistant sensors could not be turned into a custom app', ['error' => $invalidCustomApp->getMessage()]);

            return null;
        }
    }
}
