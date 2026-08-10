<?php

declare(strict_types=1);

namespace App\Provider\Claude;

use Psr\Log\LoggerInterface;

/**
 * Turns a decoded `GET /api/oauth/usage` body into a snapshot.
 *
 * The windows are read from `limits[]` and never from the top-level `five_hour` / `seven_day` keys:
 * both carry the same numbers, but `limits[]` is the discriminated shape, it is the only place the
 * model-scoped window appears, and its `percent` is an integer where `utilization` is a float.
 *
 * The response also carries a dozen null-valued codename keys. They are ignored by construction:
 * this reader looks up what it needs and never walks the top level.
 */
final readonly class ClaudeUsageResponseReader
{
    private const string LIMITS_FIELD = 'limits';
    private const string KIND_FIELD = 'kind';
    private const string PERCENT_FIELD = 'percent';
    private const string RESETS_AT_FIELD = 'resets_at';
    private const string SCOPE_FIELD = 'scope';
    private const string MODEL_FIELD = 'model';
    private const string DISPLAY_NAME_FIELD = 'display_name';
    private const string FABLE_MODEL_DISPLAY_NAME = 'Fable';
    private const string SPEND_FIELD = 'spend';
    private const string SPEND_USED_FIELD = 'used';
    private const string SPEND_LIMIT_FIELD = 'limit';
    private const string SPEND_ENABLED_FIELD = 'enabled';
    private const string AMOUNT_IN_MINOR_UNITS_FIELD = 'amount_minor';
    private const string CURRENCY_FIELD = 'currency';
    private const string EXPONENT_FIELD = 'exponent';
    private const string RESETS_AT_FORMAT = 'Y-m-d\TH:i:s.uP';
    private const string READING_TIMEZONE = 'UTC';

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<array-key, mixed> $decodedResponse
     */
    public function read(array $decodedResponse): ClaudeUsageSnapshot
    {
        $limitEntries = $this->readLimitEntries($decodedResponse);

        return ClaudeUsageSnapshot::create(
            session: $this->readWindowOfKind($limitEntries, ClaudeUsageWindowKind::Session),
            weeklyAll: $this->readWindowOfKind($limitEntries, ClaudeUsageWindowKind::WeeklyAll),
            fableWeekly: $this->readFableWindow($limitEntries),
            spend: $this->readSpend($decodedResponse),
        );
    }

    /**
     * @param array<array-key, mixed> $decodedResponse
     *
     * @return list<array<array-key, mixed>>
     */
    private function readLimitEntries(array $decodedResponse): array
    {
        $limits = $decodedResponse[self::LIMITS_FIELD] ?? null;
        if (!\is_array($limits)) {
            $this->logUnexpectedShape('the limits array is missing');

            return [];
        }

        $limitEntries = [];
        foreach ($limits as $limit) {
            if (!\is_array($limit)) {
                $this->logUnexpectedShape('a limits entry is not an object');

                continue;
            }

            $limitEntries[] = $limit;
        }

        return $limitEntries;
    }

    /**
     * @param list<array<array-key, mixed>> $limitEntries
     */
    private function readWindowOfKind(array $limitEntries, ClaudeUsageWindowKind $kind): ?ClaudeUsageWindow
    {
        foreach ($limitEntries as $limitEntry) {
            if ($kind->value === ($limitEntry[self::KIND_FIELD] ?? null)) {
                return $this->buildWindow($limitEntry, $kind);
            }
        }

        $this->logUnexpectedShape(\sprintf('the limits array carries no "%s" entry', $kind->value));

        return null;
    }

    /**
     * @param list<array<array-key, mixed>> $limitEntries
     */
    private function readFableWindow(array $limitEntries): ?ClaudeUsageWindow
    {
        foreach ($limitEntries as $limitEntry) {
            if (ClaudeUsageWindowKind::WeeklyScoped->value !== ($limitEntry[self::KIND_FIELD] ?? null)) {
                continue;
            }

            if (self::isFableScope($limitEntry[self::SCOPE_FIELD] ?? null)) {
                return $this->buildWindow($limitEntry, ClaudeUsageWindowKind::WeeklyScoped);
            }
        }

        $this->logUnexpectedShape(\sprintf('the limits array carries no weekly window scoped to the "%s" model', self::FABLE_MODEL_DISPLAY_NAME));

        return null;
    }

    /**
     * The scope is matched on a display name, which is the one field a rename upstream will silently
     * change. Comparison is case-insensitive; the name is ASCII, so byte folding is enough.
     */
    private static function isFableScope(mixed $scope): bool
    {
        if (!\is_array($scope)) {
            return false;
        }

        $model = $scope[self::MODEL_FIELD] ?? null;
        if (!\is_array($model)) {
            return false;
        }

        $displayName = $model[self::DISPLAY_NAME_FIELD] ?? null;

        return \is_string($displayName) && 0 === strcasecmp($displayName, self::FABLE_MODEL_DISPLAY_NAME);
    }

    /**
     * @param array<array-key, mixed> $limitEntry
     */
    private function buildWindow(array $limitEntry, ClaudeUsageWindowKind $kind): ?ClaudeUsageWindow
    {
        $percent = $limitEntry[self::PERCENT_FIELD] ?? null;
        if (!is_numeric($percent)) {
            $this->logUnexpectedShape(\sprintf('the "%s" entry carries no readable percent', $kind->value));

            return null;
        }

        return ClaudeUsageWindow::create($kind, (int) round((float) $percent), $this->readResetInstant($limitEntry, $kind));
    }

    /**
     * @param array<array-key, mixed> $limitEntry
     */
    private function readResetInstant(array $limitEntry, ClaudeUsageWindowKind $kind): ?\DateTimeImmutable
    {
        $rawInstant = $limitEntry[self::RESETS_AT_FIELD] ?? null;
        if (null === $rawInstant) {
            // A window without a reset instant is a supported reading, not a fault: the endpoint
            // answers null here for the model-scoped weekly window most of the time.
            return null;
        }

        if (!\is_string($rawInstant)) {
            $this->logUnexpectedShape(\sprintf('the "%s" entry carries an unreadable reset instant', $kind->value));

            return null;
        }

        $resetInstant = \DateTimeImmutable::createFromFormat(self::RESETS_AT_FORMAT, $rawInstant);
        if (false === $resetInstant) {
            try {
                $resetInstant = new \DateTimeImmutable($rawInstant);
            } catch (\DateMalformedStringException) {
                $this->logUnexpectedShape(\sprintf('the "%s" entry carries an unreadable reset instant', $kind->value));

                return null;
            }
        }

        return $resetInstant->setTimezone(new \DateTimeZone(self::READING_TIMEZONE));
    }

    /**
     * @param array<array-key, mixed> $decodedResponse
     */
    private function readSpend(array $decodedResponse): ?ClaudeSpend
    {
        $spend = $decodedResponse[self::SPEND_FIELD] ?? null;
        if (!\is_array($spend)) {
            $this->logUnexpectedShape('the spend block is missing');

            return null;
        }

        if (false === ($spend[self::SPEND_ENABLED_FIELD] ?? null)) {
            // An account with extra usage turned off has no credit balance to draw, which is a
            // reading of its own rather than a malformed answer.
            return null;
        }

        $used = $this->readMoney($spend[self::SPEND_USED_FIELD] ?? null, self::SPEND_FIELD.'.'.self::SPEND_USED_FIELD);
        $limit = $this->readMoney($spend[self::SPEND_LIMIT_FIELD] ?? null, self::SPEND_FIELD.'.'.self::SPEND_LIMIT_FIELD);

        if (null === $used || null === $limit) {
            return null;
        }

        return ClaudeSpend::create($used, $limit);
    }

    /**
     * The amount, its currency and its exponent are read off one and the same object. The
     * `extra_usage` counters are deliberately left alone: they are minor units too, but they ship
     * without an exponent, so nothing can divide them safely.
     */
    private function readMoney(mixed $money, string $fieldPath): ?ClaudeMoney
    {
        if (!\is_array($money)) {
            $this->logUnexpectedShape(\sprintf('"%s" is not an amount object', $fieldPath));

            return null;
        }

        $amountInMinorUnits = $money[self::AMOUNT_IN_MINOR_UNITS_FIELD] ?? null;
        $currency = $money[self::CURRENCY_FIELD] ?? null;
        $exponent = $money[self::EXPONENT_FIELD] ?? null;

        if (!is_numeric($amountInMinorUnits) || !\is_string($currency) || !is_numeric($exponent)) {
            $this->logUnexpectedShape(\sprintf('"%s" carries no readable amount, currency and exponent', $fieldPath));

            return null;
        }

        try {
            return ClaudeMoney::create((int) $amountInMinorUnits, $currency, (int) $exponent);
        } catch (\InvalidArgumentException $invalidAmount) {
            $this->logUnexpectedShape(\sprintf('"%s" is not a usable amount: %s', $fieldPath, $invalidAmount->getMessage()));

            return null;
        }
    }

    private function logUnexpectedShape(string $reason): void
    {
        $this->logger->warning('Unexpected Claude usage response shape', ['reason' => $reason]);
    }
}
