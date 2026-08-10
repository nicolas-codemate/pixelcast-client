<?php

declare(strict_types=1);

namespace App\Claude\Exception;

/**
 * No factory here ever receives a token: the messages travel into logs and into console output,
 * and this repository is public.
 */
final class ClaudeOAuthException extends \RuntimeException
{
    public const string INVALID_GRANT_ERROR_CODE = 'invalid_grant';

    private function __construct(
        string $message,
        public readonly ?string $oauthErrorCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function transportFailure(string $grantType, \Throwable $previous): self
    {
        return new self(
            \sprintf('The Claude token endpoint could not be reached for a "%s" grant.', $grantType),
            previous: $previous,
        );
    }

    public static function exchangeRefused(string $grantType, int $statusCode, ?string $oauthErrorCode): self
    {
        return new self(
            \sprintf(
                'The Claude token endpoint refused a "%s" grant with HTTP %d (%s).',
                $grantType,
                $statusCode,
                $oauthErrorCode ?? 'no error code',
            ),
            $oauthErrorCode,
        );
    }

    public static function unreadableResponse(string $grantType): self
    {
        return new self(\sprintf('The Claude token endpoint answered a "%s" grant with a body that is not a JSON object.', $grantType));
    }

    public static function missingTokenResponseField(string $fieldName): self
    {
        return new self(\sprintf('The Claude token response carries no usable "%s".', $fieldName));
    }

    public static function unreadableApprovalResult(): self
    {
        return new self('The pasted line is not of the form "<code>#<state>". Copy the line the approval page displays, whole.');
    }

    public static function approvalStateMismatch(): self
    {
        return new self('The approval answered with a state this run did not generate. Start "app:claude:login" again rather than exchanging that code.');
    }

    /**
     * Raised without calling anything: the pair on disk carries the mark a previous cycle left.
     */
    public static function sessionAlreadyLost(?\DateTimeImmutable $unusableSince): self
    {
        return new self(
            \sprintf(
                'The Claude session was already found unusable on %s and no refresh can revive it. Run "app:claude:login" on the device to authorise a new one.',
                $unusableSince?->format(\DateTimeInterface::RFC3339) ?? 'an earlier cycle',
            ),
            self::INVALID_GRANT_ERROR_CODE,
        );
    }

    public static function sessionLost(self $previous): self
    {
        return new self(
            'The Claude session is no longer valid and cannot be refreshed. Run "app:claude:login" on the device to authorise a new one.',
            self::INVALID_GRANT_ERROR_CODE,
            $previous,
        );
    }
}
