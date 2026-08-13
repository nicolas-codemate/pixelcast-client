<?php

declare(strict_types=1);

namespace App\Provider\GitHub;

use App\Client\CustomApp\CustomAppPayload;
use App\Config\Sync\GitHubSyncConfig;
use App\Config\SyncsConfigLoader;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Counts the pull requests matching the configured search query and draws that count as one
 * single-zone custom app.
 *
 * Nothing here throws. A missing token, an unreachable endpoint and an answer carrying no count
 * all end as a logged warning and a display the handler reports as a skipped cycle.
 */
final readonly class GitHubPullRequestProvider implements GitHubPullRequestProviderInterface
{
    private const string CUSTOM_APP_NAME = 'github';
    private const string SEARCH_ISSUES_PATH = 'search/issues';
    private const string TOTAL_COUNT_KEY = 'total_count';
    private const string ACCEPTED_MEDIA_TYPE = 'application/vnd.github+json';
    private const string API_VERSION = '2022-11-28';
    private const string TOKEN_ENVIRONMENT_VARIABLE = 'PIXELCAST_GITHUB_TOKEN';

    public function __construct(
        #[Target('github.client')]
        private HttpClientInterface $gitHubClient,
        private SyncsConfigLoader $configLoader,
        private LoggerInterface $logger,
        private ?string $token = null,
    ) {
    }

    public function fetchPullRequestCountDisplay(): PullRequestCountDisplay
    {
        if (null === $this->token || '' === $this->token) {
            $this->logger->warning('GitHub needs an access token', [
                'environment_variable' => self::TOKEN_ENVIRONMENT_VARIABLE,
            ]);

            return PullRequestCountDisplay::couldNotBeRead();
        }

        $gitHubSyncGroup = $this->configLoader->load()->syncGroupOfType(GitHubSyncConfig::class);

        $matchingPullRequestCount = $this->countMatchingPullRequests($gitHubSyncGroup->query);
        if (null === $matchingPullRequestCount) {
            return PullRequestCountDisplay::couldNotBeRead();
        }

        if (0 === $matchingPullRequestCount) {
            return PullRequestCountDisplay::removesTheApp(self::CUSTOM_APP_NAME);
        }

        return $this->buildDisplay($matchingPullRequestCount, $gitHubSyncGroup);
    }

    private function countMatchingPullRequests(string $searchQuery): ?int
    {
        try {
            /** @var array<array-key, mixed> $decodedResponse */
            $decodedResponse = $this->gitHubClient->request('GET', self::SEARCH_ISSUES_PATH, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token,
                    'Accept' => self::ACCEPTED_MEDIA_TYPE,
                    'X-GitHub-Api-Version' => self::API_VERSION,
                ],
                // One item per page: the group reads the total, never the matches themselves.
                'query' => ['q' => $searchQuery, 'per_page' => 1],
            ])->toArray();
        } catch (HttpClientExceptionInterface $httpError) {
            $this->logger->warning('The GitHub search endpoint could not be read', ['error' => $httpError->getMessage()]);

            return null;
        }

        $totalCount = $decodedResponse[self::TOTAL_COUNT_KEY] ?? null;
        if (!\is_int($totalCount)) {
            $this->logger->warning('The GitHub search answer carries no readable count');

            return null;
        }

        return $totalCount;
    }

    private function buildDisplay(int $matchingPullRequestCount, GitHubSyncConfig $gitHubSyncGroup): PullRequestCountDisplay
    {
        try {
            $customApp = CustomAppPayload::createSingleZone(
                name: self::CUSTOM_APP_NAME,
                text: (string) $matchingPullRequestCount,
                iconName: $gitHubSyncGroup->iconName,
                label: $gitHubSyncGroup->label,
                color: $gitHubSyncGroup->color,
                staleAfterInSeconds: $gitHubSyncGroup->staleDeclaration->staleAfterInSeconds,
                staleBehavior: $gitHubSyncGroup->staleDeclaration->staleBehavior,
            );
        } catch (\InvalidArgumentException $invalidCustomApp) {
            $this->logger->warning('The GitHub count could not be turned into a custom app', ['error' => $invalidCustomApp->getMessage()]);

            return PullRequestCountDisplay::couldNotBeRead();
        }

        return PullRequestCountDisplay::showsCount($customApp);
    }
}
