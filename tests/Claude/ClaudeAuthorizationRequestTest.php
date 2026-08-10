<?php

declare(strict_types=1);

namespace App\Tests\Claude;

use App\Claude\ClaudeAuthorizationRequest;
use App\Claude\Exception\ClaudeOAuthException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClaudeAuthorizationRequestTest extends TestCase
{
    private const string CODE_VERIFIER = 'code-verifier-of-this-run';
    private const string STATE = 'state-of-this-run';

    public function testTheUrlCarriesEveryParameterTheAuthorizationPageRequires(): void
    {
        $authorizationUrl = ClaudeAuthorizationRequest::create(self::CODE_VERIFIER, self::STATE)->authorizationUrl();

        $queryString = parse_url($authorizationUrl, \PHP_URL_QUERY);
        self::assertIsString($queryString);
        parse_str($queryString, $queryParameters);

        self::assertStringStartsWith('https://platform.claude.com/oauth/authorize?', $authorizationUrl);
        self::assertSame('true', $queryParameters['code'] ?? null);
        self::assertSame('code', $queryParameters['response_type'] ?? null);
        self::assertSame(ClaudeAuthorizationRequest::REDIRECT_URI, $queryParameters['redirect_uri'] ?? null);
        self::assertSame(ClaudeAuthorizationRequest::SCOPE, $queryParameters['scope'] ?? null);
        self::assertSame('S256', $queryParameters['code_challenge_method'] ?? null);
        self::assertSame(self::STATE, $queryParameters['state'] ?? null);
        self::assertSame(
            rtrim(strtr(base64_encode(hash('sha256', self::CODE_VERIFIER, binary: true)), '+/', '-_'), '='),
            $queryParameters['code_challenge'] ?? null,
        );
    }

    public function testTwoRandomRequestsShareNoValueAndStayUrlSafe(): void
    {
        $firstRequest = ClaudeAuthorizationRequest::createRandom();
        $secondRequest = ClaudeAuthorizationRequest::createRandom();

        foreach ([$firstRequest->codeVerifier, $firstRequest->state, $secondRequest->codeVerifier, $secondRequest->state] as $generatedValue) {
            self::assertSame(43, \strlen($generatedValue));
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $generatedValue);
        }

        self::assertNotSame($firstRequest->codeVerifier, $firstRequest->state);
        self::assertNotSame($firstRequest->codeVerifier, $secondRequest->codeVerifier);
        self::assertNotSame($firstRequest->state, $secondRequest->state);
    }

    public function testTheCodeIsReadBackOnlyWhenTheStateIsTheOneThisRequestGenerated(): void
    {
        $authorizationRequest = ClaudeAuthorizationRequest::create(self::CODE_VERIFIER, self::STATE);

        self::assertSame('the-code', $authorizationRequest->codeFromApprovalResult('  the-code#'.self::STATE."\n"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnusableApprovalResults(): iterable
    {
        yield 'no separator at all' => ['the-code'];
        yield 'an empty code' => ['#'.self::STATE];
        yield 'two separators' => ['the-code#'.self::STATE.'#extra'];
        yield 'nothing but the separator' => ['#'];
    }

    #[DataProvider('provideUnusableApprovalResults')]
    public function testALineThatIsNotCodeThenStateIsRefused(string $approvalResult): void
    {
        $this->expectException(ClaudeOAuthException::class);
        $this->expectExceptionMessage('<code>#<state>');

        ClaudeAuthorizationRequest::create(self::CODE_VERIFIER, self::STATE)->codeFromApprovalResult($approvalResult);
    }

    public function testAStateFromAnotherRunIsRefusedRatherThanExchanged(): void
    {
        $this->expectException(ClaudeOAuthException::class);
        $this->expectExceptionMessage('app:claude:login');

        ClaudeAuthorizationRequest::create(self::CODE_VERIFIER, self::STATE)->codeFromApprovalResult('the-code#state-of-another-run');
    }
}
