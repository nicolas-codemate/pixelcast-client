<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Claude\ClaudeAuthorizationRequest;
use App\Claude\ClaudeCredentials;
use App\Claude\ClaudeCredentialsStore;
use App\Claude\ClaudeOAuthClient;
use App\Claude\StoredClaudeCredentials;
use App\Command\ClaudeLoginCommand;
use App\Tests\Stub\StaticClaudeAuthorizationRequestFactoryStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClaudeLoginCommandTest extends TestCase
{
    private const string OAUTH_BASE_URI = 'https://platform.claude.com/v1/oauth/';
    private const string TOKEN_URL = self::OAUTH_BASE_URI.'token';
    private const string NOW = '2026-08-10T12:00:00+00:00';
    private const string CODE_VERIFIER = 'code-verifier-of-this-run';
    private const string STATE = 'state-of-this-run';
    private const string AUTHORIZATION_CODE = 'authorization-code-the-page-displayed';
    private const string ISSUED_ACCESS_TOKEN = 'access-token-just-issued';
    private const string ISSUED_REFRESH_TOKEN = 'refresh-token-just-issued';
    private const string PREEXISTING_ACCESS_TOKEN = 'access-token-already-on-disk';
    private const string PREEXISTING_REFRESH_TOKEN = 'refresh-token-already-on-disk';

    private string $temporaryDirectory;
    private string $credentialsFilePath;
    private string|false $terminalWidthBeforeTheTest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/pixelcast-claude-login-'.bin2hex(random_bytes(6));
        $this->credentialsFilePath = $this->temporaryDirectory.'/credentials.json';

        // Console blocks wrap at the terminal width, and a width of 80 would fold the messages these
        // assertions read. Pinning it keeps them looking at what the operator is told, not at where
        // the console chose to break a line.
        $this->terminalWidthBeforeTheTest = getenv('COLUMNS');
        putenv('COLUMNS=200');
    }

    protected function tearDown(): void
    {
        putenv(false === $this->terminalWidthBeforeTheTest ? 'COLUMNS' : 'COLUMNS='.$this->terminalWidthBeforeTheTest);
        new Filesystem()->remove($this->temporaryDirectory);

        parent::tearDown();
    }

    public function testAnApprovedAuthorizationIsExchangedAndWrittenAsTheFirstPair(): void
    {
        $tokenResponse = self::tokenResponse();
        $httpClient = new MockHttpClient([$tokenResponse], self::OAUTH_BASE_URI);
        $store = new ClaudeCredentialsStore($this->credentialsFilePath);
        $tester = self::createTester($httpClient, $store);
        $tester->setInputs([self::AUTHORIZATION_CODE.'#'.self::STATE]);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertSame('POST', $tokenResponse->getRequestMethod());
        self::assertSame(self::TOKEN_URL, $tokenResponse->getRequestUrl());
        self::assertSame(
            [
                'grant_type' => 'authorization_code',
                'code' => self::AUTHORIZATION_CODE,
                'client_id' => ClaudeOAuthClient::CLIENT_ID,
                'redirect_uri' => ClaudeAuthorizationRequest::REDIRECT_URI,
                'code_verifier' => self::CODE_VERIFIER,
                'state' => self::STATE,
            ],
            self::requestBody($tokenResponse),
        );

        $stored = $store->load();
        self::assertSame(self::ISSUED_ACCESS_TOKEN, $stored->current->accessToken);
        self::assertSame(self::ISSUED_REFRESH_TOKEN, $stored->current->refreshToken);
        self::assertNull($stored->previous);
    }

    public function testTheDisplayCarriesTheApprovalUrlAndTheStateButNoTokenAtAll(): void
    {
        $httpClient = new MockHttpClient([self::tokenResponse()], self::OAUTH_BASE_URI);
        $store = new ClaudeCredentialsStore($this->credentialsFilePath);
        $tester = self::createTester($httpClient, $store);
        $tester->setInputs([self::AUTHORIZATION_CODE.'#'.self::STATE]);

        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('https://platform.claude.com/oauth/authorize?', $display);
        self::assertStringContainsString('code=true', $display);
        self::assertStringContainsString('code_challenge_method=S256', $display);
        self::assertStringContainsString('client_id='.ClaudeOAuthClient::CLIENT_ID, $display);
        self::assertStringContainsString('code_challenge='.self::expectedCodeChallenge(), $display);
        self::assertStringContainsString('state='.self::STATE, $display);
        self::assertStringContainsString($this->credentialsFilePath, $display);

        foreach ([self::ISSUED_ACCESS_TOKEN, self::ISSUED_REFRESH_TOKEN] as $token) {
            self::assertStringNotContainsString($token, $display);
        }
    }

    public function testAPairAlreadyOnDiskIsLeftAloneAndTheRemedyIsNamed(): void
    {
        $store = $this->storeHoldingAPreexistingPair();
        $httpClient = new MockHttpClient([], self::OAUTH_BASE_URI);
        $tester = self::createTester($httpClient, $store);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('--force', $tester->getDisplay());
        self::assertSame(0, $httpClient->getRequestsCount());
        self::assertSame(self::PREEXISTING_ACCESS_TOKEN, $store->load()->current->accessToken);
    }

    public function testForceReplacesThePairThatWasThere(): void
    {
        $store = $this->storeHoldingAPreexistingPair();
        $httpClient = new MockHttpClient([self::tokenResponse()], self::OAUTH_BASE_URI);
        $tester = self::createTester($httpClient, $store);
        $tester->setInputs([self::AUTHORIZATION_CODE.'#'.self::STATE]);

        $exitCode = $tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $stored = $store->load();
        self::assertSame(self::ISSUED_ACCESS_TOKEN, $stored->current->accessToken);
        self::assertNull($stored->previous);
    }

    public function testAStateThatDoesNotMatchTheRunIsNeverExchanged(): void
    {
        $httpClient = new MockHttpClient([self::tokenResponse()], self::OAUTH_BASE_URI);
        $tester = self::createTester($httpClient, new ClaudeCredentialsStore($this->credentialsFilePath));
        $tester->setInputs([self::AUTHORIZATION_CODE.'#state-of-another-run']);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(0, $httpClient->getRequestsCount());
        self::assertFileDoesNotExist($this->credentialsFilePath);
    }

    public function testALineWithoutAStateIsRefusedBeforeAnyExchange(): void
    {
        $httpClient = new MockHttpClient([self::tokenResponse()], self::OAUTH_BASE_URI);
        $tester = self::createTester($httpClient, new ClaudeCredentialsStore($this->credentialsFilePath));
        $tester->setInputs([self::AUTHORIZATION_CODE]);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('<code>#<state>', $tester->getDisplay());
        self::assertSame(0, $httpClient->getRequestsCount());
        self::assertFileDoesNotExist($this->credentialsFilePath);
    }

    public function testARefusedExchangeWritesNothing(): void
    {
        $httpClient = new MockHttpClient(
            [new MockResponse(json_encode(['error' => 'invalid_grant'], \JSON_THROW_ON_ERROR), ['http_code' => 400])],
            self::OAUTH_BASE_URI,
        );
        $tester = self::createTester($httpClient, new ClaudeCredentialsStore($this->credentialsFilePath));
        $tester->setInputs([self::AUTHORIZATION_CODE.'#'.self::STATE]);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('invalid_grant', $tester->getDisplay());
        self::assertFileDoesNotExist($this->credentialsFilePath);
    }

    public function testAnUnreachableTokenEndpointFailsWithoutWritingAnything(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('', ['error' => 'connection refused'])], self::OAUTH_BASE_URI);
        $tester = self::createTester($httpClient, new ClaudeCredentialsStore($this->credentialsFilePath));
        $tester->setInputs([self::AUTHORIZATION_CODE.'#'.self::STATE]);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertFileDoesNotExist($this->credentialsFilePath);
    }

    public function testADirectoryThatCannotBeWrittenIntoIsReportedBeforeTheBrowserStep(): void
    {
        $readOnlyDirectory = $this->temporaryDirectory.'/read-only';
        new Filesystem()->mkdir($readOnlyDirectory);
        chmod($readOnlyDirectory, 0o500);
        clearstatcache(true, $readOnlyDirectory);

        if (is_writable($readOnlyDirectory)) {
            self::markTestSkipped('The permission bits of a directory do not stop the user this suite runs as.');
        }

        $httpClient = new MockHttpClient([], self::OAUTH_BASE_URI);
        $tester = self::createTester($httpClient, new ClaudeCredentialsStore($readOnlyDirectory.'/claude/credentials.json'));

        $exitCode = $tester->execute([]);

        chmod($readOnlyDirectory, 0o700);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString($readOnlyDirectory, $tester->getDisplay());
        self::assertStringNotContainsString('https://platform.claude.com/oauth/authorize', $tester->getDisplay());
    }

    private function storeHoldingAPreexistingPair(): ClaudeCredentialsStore
    {
        $store = new ClaudeCredentialsStore($this->credentialsFilePath);
        $store->save(new StoredClaudeCredentials(ClaudeCredentials::create(
            accessToken: self::PREEXISTING_ACCESS_TOKEN,
            refreshToken: self::PREEXISTING_REFRESH_TOKEN,
            expiresAt: new \DateTimeImmutable('2026-08-10T20:00:00+00:00'),
            scopes: ['user:profile'],
            obtainedAt: new \DateTimeImmutable(self::NOW),
        )));

        return $store;
    }

    private static function createTester(MockHttpClient $httpClient, ClaudeCredentialsStore $store): CommandTester
    {
        return new CommandTester(new ClaudeLoginCommand(
            new ClaudeOAuthClient($httpClient, $store, new MockClock(self::NOW), new NullLogger()),
            $store,
            new StaticClaudeAuthorizationRequestFactoryStub(
                ClaudeAuthorizationRequest::create(self::CODE_VERIFIER, self::STATE),
            ),
        ));
    }

    private static function tokenResponse(): MockResponse
    {
        return new MockResponse(
            json_encode([
                'access_token' => self::ISSUED_ACCESS_TOKEN,
                'refresh_token' => self::ISSUED_REFRESH_TOKEN,
                'expires_in' => 28800,
                'scope' => ClaudeAuthorizationRequest::SCOPE,
            ], \JSON_THROW_ON_ERROR),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    private static function expectedCodeChallenge(): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', self::CODE_VERIFIER, binary: true)), '+/', '-_'), '=');
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function requestBody(MockResponse $response): array
    {
        $body = $response->getRequestOptions()['body'] ?? null;
        if (!\is_string($body)) {
            self::fail('The token request carries no readable body.');
        }

        $decodedBody = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decodedBody)) {
            self::fail('The token request body is not a JSON object.');
        }

        return $decodedBody;
    }
}
