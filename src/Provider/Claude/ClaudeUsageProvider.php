<?php

declare(strict_types=1);

namespace App\Provider\Claude;

use App\Claude\ClaudeOAuthClient;
use App\Claude\Exception\ClaudeCredentialsException;
use App\Claude\Exception\ClaudeOAuthException;
use App\Client\Gauge\GaugePayload;
use App\Client\Gauge\GaugeRow;
use App\Config\Sync\ClaudeSyncConfig;
use App\Config\Sync\ClaudeUsageRowLabel;
use App\Config\Sync\StaleDeclaration;
use App\Config\SyncsConfigLoader;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Draws the four Claude subscription counters as one gauge.
 *
 * Nothing here throws. A refused session, an unreachable endpoint, a window the answer no longer
 * carries and a row that outgrows its own limits all end as a logged warning and one row fewer —
 * at worst no gauge at all, which the handler reports as a skipped cycle and staleness then covers.
 */
final readonly class ClaudeUsageProvider implements ClaudeUsageProviderInterface
{
    private const string GAUGE_NAME = 'claude';
    private const string USAGE_PATH = 'usage';
    private const string OAUTH_BETA_VERSION = 'oauth-2025-04-20';
    private const string API_VERSION = '2023-06-01';
    private const string GAUGE_TITLE = 'Claude';
    private const string GAUGE_ICON_NAME = 'claude';

    /**
     * The device stands in one French home while `resets_at` is absolute UTC, so reset times are
     * drawn in that home's wall clock rather than in the container's, which runs in UTC. A second
     * timezone would need a configuration key on a group that otherwise has none.
     */
    private const string RESET_TIMEZONE = 'Europe/Paris';

    private const string SESSION_RESET_FORMAT = 'H:i';
    private const string WEEKLY_RESET_FORMAT = 'd/m H\h';
    private const string PERCENT_VALUE_FORMAT = '%d%%';
    private const string CREDIT_BALANCE_FORMAT = '%s/%s %s';
    private const string DECIMAL_SEPARATOR = '.';

    public function __construct(
        #[Target('claude_usage.client')]
        private HttpClientInterface $claudeUsageClient,
        private ClaudeOAuthClient $oauthClient,
        private ClaudeUsageResponseReader $usageResponseReader,
        private SyncsConfigLoader $configLoader,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function fetchUsageGauge(): ?GaugePayload
    {
        $claudeSyncGroup = $this->configLoader->load()->syncGroupOfType(ClaudeSyncConfig::class);

        $accessToken = $this->readFreshAccessToken();
        if (null === $accessToken) {
            return null;
        }

        $decodedResponse = $this->requestUsage($accessToken);
        if (null === $decodedResponse) {
            return null;
        }

        $usageSnapshot = $this->usageResponseReader->read($decodedResponse);
        if ($usageSnapshot->isEmpty()) {
            $this->logger->warning('The Claude usage answer carries no readable window');

            return null;
        }

        $rows = $this->buildRows($usageSnapshot, $this->clock->now(), $claudeSyncGroup->hiddenRows);
        if ([] === $rows) {
            $this->logger->warning('No Claude usage row could be drawn');

            return null;
        }

        return $this->buildGauge($rows, $claudeSyncGroup->staleDeclaration);
    }

    private function readFreshAccessToken(): ?string
    {
        try {
            // Refreshed at most once, and never retried: the token endpoint rate-limits the
            // exchange itself, so a second attempt inside one cycle extends the lockout.
            return $this->oauthClient->freshAccessToken();
        } catch (ClaudeOAuthException|ClaudeCredentialsException $sessionFailure) {
            $this->logger->warning('The Claude session could not be used', ['error' => $sessionFailure->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function requestUsage(string $accessToken): ?array
    {
        try {
            /** @var array<array-key, mixed> $decodedResponse */
            $decodedResponse = $this->claudeUsageClient->request('GET', self::USAGE_PATH, [
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                    'anthropic-beta' => self::OAUTH_BETA_VERSION,
                    // Sent although the endpoint was never observed to require it: an
                    // unnecessary version header is ignored, a missing one is a 400 on every
                    // cycle, and this endpoint cannot be probed freely to find out which.
                    'anthropic-version' => self::API_VERSION,
                    'Content-Type' => 'application/json',
                ],
            ])->toArray();

            return $decodedResponse;
        } catch (HttpClientExceptionInterface $httpError) {
            $this->logger->warning('The Claude usage endpoint could not be read', ['error' => $httpError->getMessage()]);

            return null;
        }
    }

    /**
     * @param list<GaugeRow> $rows
     */
    private function buildGauge(array $rows, StaleDeclaration $staleDeclaration): ?GaugePayload
    {
        try {
            // No duration: the firmware default paginates four rows onto two pages of its own accord.
            return GaugePayload::create(
                name: self::GAUGE_NAME,
                rows: $rows,
                title: self::GAUGE_TITLE,
                iconName: self::GAUGE_ICON_NAME,
                staleAfterInSeconds: $staleDeclaration->staleAfterInSeconds,
                staleBehavior: $staleDeclaration->staleBehavior,
            );
        } catch (\InvalidArgumentException $invalidGauge) {
            $this->logger->warning('The Claude usage rows could not be turned into a gauge', ['error' => $invalidGauge->getMessage()]);

            return null;
        }
    }

    /**
     * @param list<ClaudeUsageRowLabel> $hiddenRows
     *
     * @return list<GaugeRow>
     */
    private function buildRows(ClaudeUsageSnapshot $usageSnapshot, \DateTimeImmutable $now, array $hiddenRows): array
    {
        $isVisible = static fn (ClaudeUsageRowLabel $label): bool => !\in_array($label, $hiddenRows, true);

        return array_values(array_filter([
            $isVisible(ClaudeUsageRowLabel::Session) ? $this->buildWindowRow($usageSnapshot->session, ClaudeUsageRowLabel::Session, self::SESSION_RESET_FORMAT, $now) : null,
            $isVisible(ClaudeUsageRowLabel::WeeklyAll) ? $this->buildWindowRow($usageSnapshot->weeklyAll, ClaudeUsageRowLabel::WeeklyAll, self::WEEKLY_RESET_FORMAT, $now) : null,
            $isVisible(ClaudeUsageRowLabel::Fable) ? $this->buildWindowRow($usageSnapshot->fableWeekly, ClaudeUsageRowLabel::Fable, self::WEEKLY_RESET_FORMAT, $now) : null,
            $isVisible(ClaudeUsageRowLabel::Credits) ? $this->buildCreditsRow($usageSnapshot->spend) : null,
        ]));
    }

    private function buildWindowRow(?ClaudeUsageWindow $window, ClaudeUsageRowLabel $label, string $resetFormat, \DateTimeImmutable $now): ?GaugeRow
    {
        if (null === $window) {
            return null;
        }

        $pace = UsagePace::compute($window->percent, $window->resetsAt, $window->secondsInWindow(), $now);
        $displayedPercent = self::displayablePercent($window->percent);

        try {
            return GaugeRow::create(
                label: $label->value,
                percent: $displayedPercent,
                info: self::formatResetInstant($window->resetsAt, $resetFormat),
                value: \sprintf(self::PERCENT_VALUE_FORMAT, $displayedPercent),
                note: $pace?->note(),
                barColor: ClaudeUsageColors::barColorFor($displayedPercent),
                noteColor: null === $pace ? null : ClaudeUsageColors::noteColorFor($pace),
            );
        } catch (\InvalidArgumentException $invalidRow) {
            $this->logger->warning('A Claude usage row outgrew its limits', ['label' => $label->value, 'error' => $invalidRow->getMessage()]);

            return null;
        }
    }

    private function buildCreditsRow(?ClaudeSpend $spend): ?GaugeRow
    {
        if (null === $spend) {
            return null;
        }

        $percent = $spend->percent();
        if (null === $percent) {
            $this->logger->warning('The Claude credit balance carries no usable limit');

            return null;
        }

        $displayedPercent = self::displayablePercent($percent);

        try {
            return GaugeRow::create(
                label: ClaudeUsageRowLabel::Credits->value,
                percent: $displayedPercent,
                info: self::formatCreditBalance($spend),
                value: \sprintf(self::PERCENT_VALUE_FORMAT, $displayedPercent),
                barColor: ClaudeUsageColors::barColorFor($displayedPercent),
            );
        } catch (\InvalidArgumentException $invalidRow) {
            $this->logger->warning('A Claude usage row outgrew its limits', ['label' => ClaudeUsageRowLabel::Credits->value, 'error' => $invalidRow->getMessage()]);

            return null;
        }
    }

    /**
     * The bar is clamped by the row itself, so the printed percentage is clamped here too: a bar
     * full at 100 next to a value reading 101% would tell two stories on one line.
     */
    private static function displayablePercent(int $percent): int
    {
        return max(GaugeRow::MINIMUM_PERCENT, min(GaugeRow::MAXIMUM_PERCENT, $percent));
    }

    private static function formatResetInstant(?\DateTimeImmutable $resetsAt, string $resetFormat): ?string
    {
        return $resetsAt?->setTimezone(new \DateTimeZone(self::RESET_TIMEZONE))->format($resetFormat);
    }

    /**
     * The money is the whole reason the credits row reads the minor units at all, so an amount pair
     * that outgrows the row is dropped whole rather than cut: half an amount reads as another amount.
     */
    private static function formatCreditBalance(ClaudeSpend $spend): ?string
    {
        $creditBalance = \sprintf(
            self::CREDIT_BALANCE_FORMAT,
            self::formatAmount($spend->used),
            self::formatAmount($spend->limit),
            $spend->limit->currency,
        );

        return mb_strlen($creditBalance) > GaugeRow::MAXIMUM_INFO_LENGTH ? null : $creditBalance;
    }

    private static function formatAmount(ClaudeMoney $money): string
    {
        $formattedAmount = number_format($money->amount(), $money->exponent, self::DECIMAL_SEPARATOR, '');
        if (!str_contains($formattedAmount, self::DECIMAL_SEPARATOR)) {
            return $formattedAmount;
        }

        return rtrim(rtrim($formattedAmount, '0'), self::DECIMAL_SEPARATOR);
    }
}
