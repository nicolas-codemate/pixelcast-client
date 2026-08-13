<?php

declare(strict_types=1);

namespace App\Provider\GitHub;

use App\Config\Exception\PixelCastConfigException;

interface GitHubPullRequestProviderInterface
{
    /**
     * @throws PixelCastConfigException when pixelcast.yaml is missing or invalid
     */
    public function fetchPullRequestCountDisplay(): PullRequestCountDisplay;
}
