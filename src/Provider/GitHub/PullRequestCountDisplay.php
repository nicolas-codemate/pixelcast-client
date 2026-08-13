<?php

declare(strict_types=1);

namespace App\Provider\GitHub;

use App\Client\CustomApp\CustomAppPayload;

/**
 * What the device should show for the pull request count: the app to push, or the name of the app
 * to remove when the query matches nothing. At most one of the two is ever set, and both stay null
 * when the count could not be read, which leaves the screen as it stands rather than replacing a
 * known count with a guess.
 */
final readonly class PullRequestCountDisplay
{
    private function __construct(
        public ?CustomAppPayload $customAppToPush,
        public ?string $customAppNameToDelete,
    ) {
    }

    public static function showsCount(CustomAppPayload $customAppToPush): self
    {
        return new self($customAppToPush, null);
    }

    public static function removesTheApp(string $customAppName): self
    {
        return new self(null, $customAppName);
    }

    public static function couldNotBeRead(): self
    {
        return new self(null, null);
    }
}
