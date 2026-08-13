<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Provider\GitHub\GitHubPullRequestProviderInterface;
use App\Provider\GitHub\PullRequestCountDisplay;

final class StaticGitHubPullRequestProviderStub implements GitHubPullRequestProviderInterface
{
    public function __construct(
        private readonly ?PullRequestCountDisplay $countDisplay = null,
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function fetchPullRequestCountDisplay(): PullRequestCountDisplay
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->countDisplay ?? PullRequestCountDisplay::couldNotBeRead();
    }
}
