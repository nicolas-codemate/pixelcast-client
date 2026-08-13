<?php

declare(strict_types=1);

namespace App\Tests\Provider\GitHub;

use App\Provider\GitHub\GitHubPullRequestProvider;
use App\Scenario\Validation\OutboundOpenApiValidatorFactory;
use App\Scenario\Validation\OutboundPayloadValidator;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use App\Tests\Stub\RecordingLoggerStub;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubPullRequestProviderTest extends TestCase
{
    private const string SEARCH_BASE_URI = 'https://api.github.com/';
    private const string CONFIG_FIXTURE = 'tests/Config/Fixtures/syncs-github-enabled.yaml';
    private const string CUSTOM_LOOK_CONFIG_FIXTURE = 'tests/Config/Fixtures/syncs-github-custom-look.yaml';
    private const string DEVICE_BASE_URL = 'http://simulator:8080/api';
    private const string TOKEN = 'ghp-test-token';

    public function testANonZeroCountIsDrawnAsTheSingleZoneApp(): void
    {
        $display = $this->buildProvider(self::searchClient([self::jsonResponse(['total_count' => 3])]))->fetchPullRequestCountDisplay();

        self::assertNull($display->customAppNameToDelete);
        self::assertNotNull($display->customAppToPush);
        self::assertSame('github', $display->customAppToPush->name);
        self::assertSame(
            [
                'text' => '3',
                'icon' => 'github',
                'label' => 'A relire',
                'color' => '#8957E5',
                'staleAfter' => 900,
                'staleBehavior' => 'hide',
            ],
            $display->customAppToPush->toArray(),
        );
    }

    public function testTheSearchRequestCarriesTheQueryTheTokenAndTheApiVersion(): void
    {
        $searchResponse = self::jsonResponse(['total_count' => 3]);

        $this->buildProvider(self::searchClient([$searchResponse]))->fetchPullRequestCountDisplay();

        self::assertSame('GET', $searchResponse->getRequestMethod());
        self::assertSame(
            self::SEARCH_BASE_URI.'search/issues?q=is:open%20is:pr%20review-requested:@me&per_page=1',
            $searchResponse->getRequestUrl(),
        );

        $requestHeaders = self::requestHeaders($searchResponse);
        self::assertContains('Authorization: Bearer '.self::TOKEN, $requestHeaders);
        self::assertContains('Accept: application/vnd.github+json', $requestHeaders);
        self::assertContains('X-GitHub-Api-Version: 2022-11-28', $requestHeaders);
    }

    public function testAZeroCountRemovesTheAppRatherThanShowingIt(): void
    {
        $display = $this->buildProvider(self::searchClient([self::jsonResponse(['total_count' => 0])]))->fetchPullRequestCountDisplay();

        self::assertNull($display->customAppToPush);
        self::assertSame('github', $display->customAppNameToDelete);
    }

    public function testAMissingTokenNamesItsEnvironmentVariableAndSpendsNoRequest(): void
    {
        foreach ([null, ''] as $missingToken) {
            $logger = new RecordingLoggerStub();
            $searchClient = self::searchClient([self::jsonResponse(['total_count' => 3])]);

            $display = $this->buildProvider($searchClient, $logger, token: $missingToken)->fetchPullRequestCountDisplay();

            self::assertNull($display->customAppToPush);
            self::assertNull($display->customAppNameToDelete);
            self::assertSame(0, $searchClient->getRequestsCount());
            self::assertSame([['environment_variable' => 'PIXELCAST_GITHUB_TOKEN']], self::loggedContexts($logger, 'warning'));
        }
    }

    public function testAnUnreachableEndpointIsLoggedAndLeavesTheScreenAlone(): void
    {
        $logger = new RecordingLoggerStub();

        $display = $this->buildProvider(self::searchClient([new MockResponse('', ['error' => 'connection refused'])]), $logger)->fetchPullRequestCountDisplay();

        self::assertNull($display->customAppToPush);
        self::assertNull($display->customAppNameToDelete);
        self::assertStringContainsString('search endpoint could not be read', self::loggedText($logger));
    }

    public function testARefusedSearchIsLoggedAndLeavesTheScreenAlone(): void
    {
        $logger = new RecordingLoggerStub();

        $display = $this->buildProvider(self::searchClient([self::jsonResponse(['message' => 'Validation Failed'], 422)]), $logger)->fetchPullRequestCountDisplay();

        self::assertNull($display->customAppToPush);
        self::assertNull($display->customAppNameToDelete);
        self::assertStringContainsString('search endpoint could not be read', self::loggedText($logger));
    }

    public function testAnAnswerWithoutACountIsLoggedAndLeavesTheScreenAlone(): void
    {
        $logger = new RecordingLoggerStub();

        $display = $this->buildProvider(self::searchClient([self::jsonResponse(['items' => []])]), $logger)->fetchPullRequestCountDisplay();

        self::assertNull($display->customAppToPush);
        self::assertNull($display->customAppNameToDelete);
        self::assertStringContainsString('no readable count', self::loggedText($logger));
    }

    public function testTheConfiguredIconAndColourOverrideTheDefaults(): void
    {
        $display = $this->buildProvider(
            self::searchClient([self::jsonResponse(['total_count' => 12])]),
            configFixture: self::CUSTOM_LOOK_CONFIG_FIXTURE,
        )->fetchPullRequestCountDisplay();

        self::assertNotNull($display->customAppToPush);
        $customAppBody = $display->customAppToPush->toArray();
        self::assertSame('bell', $customAppBody['icon'] ?? null);
        self::assertSame('#00D4FF', $customAppBody['color'] ?? null);
    }

    public function testTheProducedPayloadIsAcceptedByTheDeviceSpecification(): void
    {
        $display = $this->buildProvider(self::searchClient([self::jsonResponse(['total_count' => 3])]))->fetchPullRequestCountDisplay();

        $customApp = $display->customAppToPush;
        self::assertNotNull($customApp);

        $validatorFactory = new OutboundOpenApiValidatorFactory(\dirname(__DIR__, 3), self::DEVICE_BASE_URL);
        $validator = new OutboundPayloadValidator($validatorFactory->create(), new Psr17Factory(), self::DEVICE_BASE_URL);

        $result = $validator->validateRequest('POST', '/custom', ['name' => $customApp->name], $customApp->toArray());

        self::assertTrue($result->valid, $result->errorMessage ?? '');
    }

    private function buildProvider(
        MockHttpClient $searchClient,
        ?RecordingLoggerStub $logger = null,
        string $configFixture = self::CONFIG_FIXTURE,
        ?string $token = self::TOKEN,
    ): GitHubPullRequestProvider {
        return new GitHubPullRequestProvider(
            $searchClient,
            SyncsConfigLoaderFactory::forConfigFile(SyncsConfigLoaderFactory::projectFilePath($configFixture)),
            $logger ?? new RecordingLoggerStub(),
            $token,
        );
    }

    /**
     * @param list<MockResponse> $responses
     */
    private static function searchClient(array $responses): MockHttpClient
    {
        return new MockHttpClient($responses, self::SEARCH_BASE_URI);
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
            self::fail('The search request carries no readable headers.');
        }

        $stringHeaders = [];
        foreach ($headers as $header) {
            if (\is_string($header)) {
                $stringHeaders[] = $header;
            }
        }

        return $stringHeaders;
    }

    /**
     * @return list<array<mixed>>
     */
    private static function loggedContexts(RecordingLoggerStub $logger, string $level): array
    {
        $contexts = [];
        foreach ($logger->records as $record) {
            if ($level === $record['level']) {
                $contexts[] = $record['context'];
            }
        }

        return $contexts;
    }

    private static function loggedText(RecordingLoggerStub $logger): string
    {
        return json_encode($logger->records, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
