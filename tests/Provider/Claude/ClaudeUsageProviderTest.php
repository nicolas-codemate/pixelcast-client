<?php

declare(strict_types=1);

namespace App\Tests\Provider\Claude;

use App\Claude\ClaudeCredentials;
use App\Claude\ClaudeCredentialsStore;
use App\Claude\ClaudeOAuthClient;
use App\Claude\StoredClaudeCredentials;
use App\Client\StaleBehavior;
use App\Provider\Claude\ClaudeUsageColors;
use App\Provider\Claude\ClaudeUsageProvider;
use App\Provider\Claude\ClaudeUsageResponseReader;
use App\Scenario\Validation\OutboundOpenApiValidatorFactory;
use App\Scenario\Validation\OutboundPayloadValidator;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use App\Tests\Stub\RecordingLoggerStub;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClaudeUsageProviderTest extends TestCase
{
    private const string USAGE_BASE_URI = 'https://api.anthropic.com/api/oauth/';
    private const string USAGE_URL = self::USAGE_BASE_URI.'usage';
    private const string OAUTH_BASE_URI = 'https://platform.claude.com/v1/oauth/';
    private const string CONFIG_FIXTURE = 'tests/Config/Fixtures/syncs-claude-enabled.yaml';
    private const string USAGE_FIXTURE_PATH = __DIR__.'/Fixtures/claude-usage.json';
    private const string DEVICE_BASE_URL = 'http://simulator:8080/api';

    /**
     * Puts the 5 hour window 6000 seconds into its 18000 and the 7 day window 171000 seconds into
     * its 604800: both far enough in for a pace to be drawn, neither past its reset.
     */
    private const string NOW = '2026-01-01T14:30:00+00:00';

    /**
     * The reference fixture leaves the Fable window without a reset instant, so the tests that need
     * one on that row rebuild the fixture around this instant, the same as the 7 day window carries.
     */
    private const string FABLE_RESET_INSTANT = '2026-01-06T15:00:00.000000+00:00';

    private const string FAR_FROM_EXPIRY = '2026-01-01T18:00:00+00:00';
    private const string CLOSE_TO_EXPIRY = '2026-01-01T14:31:00+00:00';
    private const string STORED_ACCESS_TOKEN = 'access-token-on-disk';
    private const string STORED_REFRESH_TOKEN = 'refresh-token-on-disk';
    private const string ISSUED_ACCESS_TOKEN = 'access-token-just-issued';

    private string $temporaryDirectory;
    private string $credentialsFilePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/pixelcast-claude-usage-'.bin2hex(random_bytes(6));
        $this->credentialsFilePath = $this->temporaryDirectory.'/credentials.json';
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->temporaryDirectory);

        parent::tearDown();
    }

    public function testTheFixtureIsDrawnAsTheFourRowsOfTheClaudeGauge(): void
    {
        $this->storeCredentialsExpiringAt(self::FAR_FROM_EXPIRY);

        $gauge = $this->buildProvider(self::usageClient([self::usageFixtureResponse()]))->fetchUsageGauge();

        self::assertNotNull($gauge);
        self::assertSame('claude', $gauge->name);
        self::assertSame([['t' => 'Claude', 'c' => ClaudeUsageColors::TITLE_HEX_CODE]], $gauge->toArray()['title'] ?? null);
        self::assertSame('claude', $gauge->iconName);
        self::assertNull($gauge->displayDurationMilliseconds);
        self::assertSame(2700, $gauge->staleAfterInSeconds);
        self::assertSame(StaleBehavior::Dim, $gauge->staleBehavior);

        self::assertSame(
            [
                [
                    'label' => [
                        ['t' => '5h', 'c' => ClaudeUsageColors::SESSION_LABEL_HEX_CODE],
                    ],
                    'info' => '18:50',
                    'value' => '41%',
                    'percent' => 41,
                    'note' => 'x1.2^',
                    'color' => ClaudeUsageColors::GREEN_HEX_CODE,
                    'noteColor' => ClaudeUsageColors::YELLOW_HEX_CODE,
                ],
                [
                    'label' => [
                        ['t' => '7j', 'c' => ClaudeUsageColors::WEEKLY_LABEL_HEX_CODE],
                    ],
                    'info' => '06/01 16h',
                    'value' => '28%',
                    'percent' => 28,
                    'note' => 'x1.0>',
                    'color' => ClaudeUsageColors::GREEN_HEX_CODE,
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
                    'info' => '2.5/170 EUR',
                    'value' => '1%',
                    'percent' => 1,
                    'color' => ClaudeUsageColors::GREEN_HEX_CODE,
                ],
            ],
            $gauge->toArray()['rows'],
        );
    }

    public function testTheProducedPayloadIsAcceptedByTheDeviceSpecification(): void
    {
        $this->storeCredentialsExpiringAt(self::FAR_FROM_EXPIRY);

        $gauge = $this->buildProvider(self::usageClient([self::usageFixtureResponse()]))->fetchUsageGauge();
        self::assertNotNull($gauge);

        $validatorFactory = new OutboundOpenApiValidatorFactory(\dirname(__DIR__, 3), self::DEVICE_BASE_URL);
        $validator = new OutboundPayloadValidator($validatorFactory->create(), new Psr17Factory(), self::DEVICE_BASE_URL);

        $result = $validator->validateRequest('POST', '/gauge', ['name' => $gauge->name], $gauge->toArray());

        self::assertTrue($result->valid, $result->errorMessage ?? '');
    }

    public function testTheUsageRequestCarriesTheBearerTokenAndTheBetaHeader(): void
    {
        $this->storeCredentialsExpiringAt(self::FAR_FROM_EXPIRY);
        $usageResponse = self::usageFixtureResponse();

        $this->buildProvider(self::usageClient([$usageResponse]))->fetchUsageGauge();

        $requestHeaders = self::requestHeaders($usageResponse);
        self::assertSame('GET', $usageResponse->getRequestMethod());
        self::assertSame(self::USAGE_URL, $usageResponse->getRequestUrl());
        self::assertContains('Authorization: Bearer '.self::STORED_ACCESS_TOKEN, $requestHeaders);
        self::assertContains('anthropic-beta: oauth-2025-04-20', $requestHeaders);
        self::assertContains('Content-Type: application/json', $requestHeaders);
    }

    public function testAPairFarFromItsExpiryIsUsedAsIsWithoutTouchingTheTokenEndpoint(): void
    {
        $this->storeCredentialsExpiringAt(self::FAR_FROM_EXPIRY);
        $usageClient = self::usageClient([self::usageFixtureResponse()]);
        $oauthClient = self::oauthClient([]);

        self::assertNotNull($this->buildProvider($usageClient, $oauthClient)->fetchUsageGauge());
        self::assertSame(0, $oauthClient->getRequestsCount());
        self::assertSame(1, $usageClient->getRequestsCount());
    }

    public function testAPairNearItsExpiryIsRefreshedAndTheUsageCallCarriesTheNewToken(): void
    {
        $store = $this->storeCredentialsExpiringAt(self::CLOSE_TO_EXPIRY);
        $usageResponse = self::usageFixtureResponse();
        $usageClient = self::usageClient([$usageResponse]);
        $oauthClient = self::oauthClient([self::jsonResponse([
            'access_token' => self::ISSUED_ACCESS_TOKEN,
            'refresh_token' => 'refresh-token-just-issued',
            'expires_in' => 28800,
        ])]);

        self::assertNotNull($this->buildProvider($usageClient, $oauthClient)->fetchUsageGauge());

        self::assertSame(1, $oauthClient->getRequestsCount());
        self::assertSame(1, $usageClient->getRequestsCount());
        self::assertContains('Authorization: Bearer '.self::ISSUED_ACCESS_TOKEN, self::requestHeaders($usageResponse));
        self::assertSame(self::ISSUED_ACCESS_TOKEN, $store->load()->current->accessToken);
    }

    public function testAMissingCredentialsFileCostsTheGaugeAndSpendsNoRequest(): void
    {
        $logger = new RecordingLoggerStub();
        $usageClient = self::usageClient([self::usageFixtureResponse()]);
        $oauthClient = self::oauthClient([]);

        $gauge = $this->buildProvider($usageClient, $oauthClient, $logger)->fetchUsageGauge();

        self::assertNull($gauge);
        self::assertSame(0, $usageClient->getRequestsCount());
        self::assertSame(0, $oauthClient->getRequestsCount());
        self::assertStringContainsString($this->credentialsFilePath, self::loggedText($logger));
    }

    public function testARefusedRefreshCostsTheGaugeAndNeverReachesTheUsageEndpoint(): void
    {
        $this->storeCredentialsExpiringAt(self::CLOSE_TO_EXPIRY);
        $logger = new RecordingLoggerStub();
        $usageClient = self::usageClient([self::usageFixtureResponse()]);

        $gauge = $this->buildProvider($usageClient, self::oauthClient([self::jsonResponse(['error' => 'invalid_grant'], 400)]), $logger)->fetchUsageGauge();

        self::assertNull($gauge);
        self::assertSame(0, $usageClient->getRequestsCount());
        self::assertStringContainsString('app:claude:login', self::loggedText($logger));
    }

    public function testARefusedUsageCallIsAWarningRatherThanAnException(): void
    {
        $this->storeCredentialsExpiringAt(self::FAR_FROM_EXPIRY);
        $logger = new RecordingLoggerStub();

        $gauge = $this->buildProvider(self::usageClient([self::jsonResponse(['error' => 'unauthorized'], 401)]), logger: $logger)->fetchUsageGauge();

        self::assertNull($gauge);
        self::assertStringContainsString('usage endpoint could not be read', self::loggedText($logger));
    }

    public function testATransportFailureOnTheUsageCallIsAWarningRatherThanAnException(): void
    {
        $this->storeCredentialsExpiringAt(self::FAR_FROM_EXPIRY);
        $logger = new RecordingLoggerStub();

        $gauge = $this->buildProvider(self::usageClient([new MockResponse('', ['error' => 'connection refused'])]), logger: $logger)->fetchUsageGauge();

        self::assertNull($gauge);
        self::assertStringContainsString('usage endpoint could not be read', self::loggedText($logger));
    }

    public function testAnAnswerWithoutTheModelScopedWindowStillDrawsTheOtherThreeRows(): void
    {
        $this->storeCredentialsExpiringAt(self::FAR_FROM_EXPIRY);
        $logger = new RecordingLoggerStub();
        $limitEntries = self::fixtureLimitEntries();
        $decodedFixture = self::decodedUsageFixture();
        $decodedFixture['limits'] = [$limitEntries[0], $limitEntries[1]];

        $gauge = $this->buildProvider(self::usageClient([self::jsonResponse($decodedFixture)]), logger: $logger)->fetchUsageGauge();

        self::assertNotNull($gauge);
        self::assertSame(['5h', '7j', 'credits'], self::rowLabels($gauge->toArray()['rows']));
        self::assertStringContainsString('Fable', self::loggedText($logger));
    }

    public function testAnAnswerWithNoWindowAndNoCreditBalanceCostsTheWholeGauge(): void
    {
        $this->storeCredentialsExpiringAt(self::FAR_FROM_EXPIRY);
        $logger = new RecordingLoggerStub();
        $spend = self::fixtureSpend();
        $spend['enabled'] = false;
        $decodedFixture = self::decodedUsageFixture();
        $decodedFixture['limits'] = [];
        $decodedFixture['spend'] = $spend;

        $gauge = $this->buildProvider(self::usageClient([self::jsonResponse($decodedFixture)]), logger: $logger)->fetchUsageGauge();

        self::assertNull($gauge);
        self::assertStringContainsString('no readable window', self::loggedText($logger));
    }

    public function testAPercentageAboveTheCeilingIsClampedRatherThanCostingItsRow(): void
    {
        $this->storeCredentialsExpiringAt(self::FAR_FROM_EXPIRY);
        $limitEntries = self::fixtureLimitEntries();
        $limitEntries[0]['percent'] = 101;
        $decodedFixture = self::decodedUsageFixture();
        $decodedFixture['limits'] = $limitEntries;

        $gauge = $this->buildProvider(self::usageClient([self::jsonResponse($decodedFixture)]))->fetchUsageGauge();

        self::assertNotNull($gauge);
        $sessionRow = $gauge->toArray()['rows'][0];
        self::assertSame(100, $sessionRow['percent']);
        self::assertSame('100%', $sessionRow['value'] ?? null);
        self::assertSame(ClaudeUsageColors::RED_HEX_CODE, $sessionRow['color'] ?? null);
    }

    public function testAWindowWithoutAResetInstantCarriesNeitherAnInfoNorANote(): void
    {
        $this->storeCredentialsExpiringAt(self::FAR_FROM_EXPIRY);

        $gauge = $this->buildProvider(self::usageClient([self::usageFixtureResponse()]))->fetchUsageGauge();

        self::assertNotNull($gauge);
        $fableRow = $gauge->toArray()['rows'][2];
        self::assertArrayNotHasKey('info', $fableRow);
        self::assertArrayNotHasKey('note', $fableRow);
        self::assertArrayNotHasKey('noteColor', $fableRow);
    }

    /**
     * The label is the field the row cuts short once the value and the info are served, so a name
     * followed by a second word loses that word on the last glyph that fits. The name stands alone
     * even on the window that announces a reset instant, which the info column next to it carries.
     */
    public function testAWindowAnnouncingAResetInstantKeepsItsNameAlone(): void
    {
        $this->storeCredentialsExpiringAt(self::FAR_FROM_EXPIRY);

        $gauge = $this->buildProvider(self::usageClient([self::jsonResponse(self::decodedFixtureWithTheFableWindowResettingAt())]))->fetchUsageGauge();

        self::assertNotNull($gauge);
        self::assertSame(
            [['t' => 'fable', 'c' => ClaudeUsageColors::FABLE_LABEL_HEX_CODE]],
            self::labelSegments($gauge->toArray()['rows'][2]['label']),
        );
    }

    public function testACreditBalanceWithoutAUsableLimitCostsItsRowOnly(): void
    {
        $this->storeCredentialsExpiringAt(self::FAR_FROM_EXPIRY);
        $logger = new RecordingLoggerStub();
        $spend = self::fixtureSpend();
        $spend['limit'] = ['amount_minor' => 0, 'currency' => 'EUR', 'exponent' => 2];
        $decodedFixture = self::decodedUsageFixture();
        $decodedFixture['spend'] = $spend;

        $gauge = $this->buildProvider(self::usageClient([self::jsonResponse($decodedFixture)]), logger: $logger)->fetchUsageGauge();

        self::assertNotNull($gauge);
        self::assertSame(['5h', '7j', 'fable'], self::rowLabels($gauge->toArray()['rows']));
        self::assertStringContainsString('no usable limit', self::loggedText($logger));
    }

    public function testNothingThatIsLoggedCarriesATokenValue(): void
    {
        $this->storeCredentialsExpiringAt(self::CLOSE_TO_EXPIRY);
        $logger = new RecordingLoggerStub();

        $this->buildProvider(
            self::usageClient([self::usageFixtureResponse()]),
            self::oauthClient([self::jsonResponse(['error' => 'invalid_grant'], 400)]),
            $logger,
        )->fetchUsageGauge();

        $loggedText = self::loggedText($logger);
        self::assertNotSame('[]', $loggedText);
        self::assertStringNotContainsString(self::STORED_ACCESS_TOKEN, $loggedText);
        self::assertStringNotContainsString(self::STORED_REFRESH_TOKEN, $loggedText);
    }

    public function testAHiddenRowIsLeftOutOfTheGaugeEvenWhenTheAnswerCarriesIt(): void
    {
        $this->storeCredentialsExpiringAt(self::FAR_FROM_EXPIRY);

        $gauge = $this->buildProvider(self::usageClient([self::usageFixtureResponse()]), configFixture: 'tests/Config/Fixtures/syncs-claude-hidden-credits.yaml')->fetchUsageGauge();

        self::assertNotNull($gauge);
        self::assertSame(['5h', '7j', 'fable'], self::rowLabels($gauge->toArray()['rows']));
    }

    public function testHidingEveryRowLeavesTheGroupPushingNothing(): void
    {
        $this->storeCredentialsExpiringAt(self::FAR_FROM_EXPIRY);

        $gauge = $this->buildProvider(self::usageClient([self::usageFixtureResponse()]), configFixture: 'tests/Config/Fixtures/syncs-claude-all-rows-hidden.yaml')->fetchUsageGauge();

        self::assertNull($gauge);
    }

    private function buildProvider(
        MockHttpClient $usageClient,
        ?MockHttpClient $oauthClient = null,
        ?RecordingLoggerStub $logger = null,
        string $configFixture = self::CONFIG_FIXTURE,
    ): ClaudeUsageProvider {
        $clock = new MockClock(self::NOW);
        $store = new ClaudeCredentialsStore($this->credentialsFilePath);
        $recordingLogger = $logger ?? new NullLogger();

        return new ClaudeUsageProvider(
            $usageClient,
            new ClaudeOAuthClient($oauthClient ?? self::oauthClient([]), $store, $clock, $recordingLogger),
            new ClaudeUsageResponseReader($recordingLogger),
            SyncsConfigLoaderFactory::forConfigFile(SyncsConfigLoaderFactory::projectFilePath($configFixture)),
            $clock,
            $recordingLogger,
        );
    }

    private function storeCredentialsExpiringAt(string $expiresAt): ClaudeCredentialsStore
    {
        $store = new ClaudeCredentialsStore($this->credentialsFilePath);
        $store->save(new StoredClaudeCredentials(
            ClaudeCredentials::create(
                accessToken: self::STORED_ACCESS_TOKEN,
                refreshToken: self::STORED_REFRESH_TOKEN,
                expiresAt: new \DateTimeImmutable($expiresAt),
                scopes: ['user:profile'],
                obtainedAt: new \DateTimeImmutable(self::NOW),
            ),
            null,
        ));

        return $store;
    }

    /**
     * @param list<MockResponse> $responses
     */
    private static function usageClient(array $responses): MockHttpClient
    {
        return new MockHttpClient($responses, self::USAGE_BASE_URI);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private static function oauthClient(array $responses): MockHttpClient
    {
        return new MockHttpClient($responses, self::OAUTH_BASE_URI);
    }

    private static function usageFixtureResponse(): MockResponse
    {
        return MockResponse::fromFile(self::USAGE_FIXTURE_PATH);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decodedUsageFixture(): array
    {
        $rawFixture = file_get_contents(self::USAGE_FIXTURE_PATH);
        if (false === $rawFixture) {
            self::fail('The Claude usage fixture could not be read.');
        }

        $decodedFixture = json_decode($rawFixture, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decodedFixture)) {
            self::fail('The Claude usage fixture is not a JSON object.');
        }

        return $decodedFixture;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function fixtureLimitEntries(): array
    {
        $limits = self::decodedUsageFixture()['limits'] ?? null;
        if (!\is_array($limits)) {
            self::fail('The Claude usage fixture carries no limits array.');
        }

        $limitEntries = [];
        foreach ($limits as $limit) {
            if (!\is_array($limit)) {
                self::fail('A limits entry of the Claude usage fixture is not an object.');
            }

            $limitEntries[] = $limit;
        }

        return $limitEntries;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decodedFixtureWithTheFableWindowResettingAt(): array
    {
        $limitEntries = self::fixtureLimitEntries();
        $limitEntries[2]['resets_at'] = self::FABLE_RESET_INSTANT;
        $decodedFixture = self::decodedUsageFixture();
        $decodedFixture['limits'] = $limitEntries;

        return $decodedFixture;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function fixtureSpend(): array
    {
        $spend = self::decodedUsageFixture()['spend'] ?? null;
        if (!\is_array($spend)) {
            self::fail('The Claude usage fixture carries no spend block.');
        }

        return $spend;
    }

    /**
     * @param string|list<array{t: string, c: string}> $label
     *
     * @return list<array{t: string, c: string}>
     */
    private static function labelSegments(string|array $label): array
    {
        if (\is_string($label)) {
            self::fail('The row label travels as a plain string rather than as colored segments.');
        }

        return $label;
    }

    /**
     * @param list<array{label: string|list<array{t: string, c: string}>, info?: string, value?: string, percent: int, note?: string, color?: string, noteColor?: string}> $rows
     *
     * @return list<string>
     */
    private static function rowLabels(array $rows): array
    {
        return array_map(
            static fn (array $row): string => \is_string($row['label']) ? $row['label'] : $row['label'][0]['t'],
            $rows,
        );
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function jsonResponse(array $body, int $statusCode = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $statusCode]);
    }

    /**
     * @return list<string>
     */
    private static function requestHeaders(MockResponse $response): array
    {
        $headers = $response->getRequestOptions()['headers'] ?? [];
        if (!\is_array($headers)) {
            self::fail('The usage request carries no readable headers.');
        }

        $stringHeaders = [];
        foreach ($headers as $header) {
            if (\is_string($header)) {
                $stringHeaders[] = $header;
            }
        }

        return $stringHeaders;
    }

    private static function loggedText(RecordingLoggerStub $logger): string
    {
        return json_encode($logger->records, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
