<?php

declare(strict_types=1);

namespace App\Tracker;

use Psr\Log\LoggerInterface;

/**
 * Keeps the highest price known for each tracked asset in a SQLite file under var/share, which the
 * production compose file mounts on a named volume so that the data survives a redeploy.
 *
 * Nothing here throws: an unreachable file costs a tracker its bottom row, never the tracker
 * itself. And nothing here lowers a stored high — the only statement that writes carries the
 * "strictly higher" condition, so bringing a wrong value down means forgetting it first.
 *
 * Two processes write to that file, the scheduler consumer and the app:tracker:ath command, hence
 * the single atomic upsert, the WAL journal and the busy timeout. The high that callers display is
 * then read back by a separate SELECT, which may well see what the other process wrote in between:
 * that is exactly the highest price known at this instant, which is what has to be shown.
 */
final class AllTimeHighStore
{
    private const int DATABASE_DIRECTORY_MODE = 0o755;
    private const int BUSY_TIMEOUT_IN_MILLISECONDS = 5_000;

    private const string CREATE_TABLE_STATEMENT = <<<'SQL'
        CREATE TABLE IF NOT EXISTS tracker_all_time_high (
            sync_type   TEXT NOT NULL,
            symbol      TEXT NOT NULL,
            currency    TEXT NOT NULL,
            price       REAL NOT NULL,
            reached_at  TEXT,
            observed_at TEXT NOT NULL,
            PRIMARY KEY (sync_type, symbol, currency)
        )
        SQL;

    private const string RAISE_STATEMENT = <<<'SQL'
        INSERT INTO tracker_all_time_high (sync_type, symbol, currency, price, reached_at, observed_at)
        VALUES (:sync_type, :symbol, :currency, :price, :reached_at, :observed_at)
        ON CONFLICT (sync_type, symbol, currency) DO UPDATE SET
            price = excluded.price,
            reached_at = excluded.reached_at,
            observed_at = excluded.observed_at
        WHERE excluded.price > tracker_all_time_high.price
        SQL;

    private const string SELECT_STATEMENT = <<<'SQL'
        SELECT price, reached_at, observed_at
        FROM tracker_all_time_high
        WHERE sync_type = :sync_type AND symbol = :symbol AND currency = :currency
        SQL;

    private const string DELETE_STATEMENT = <<<'SQL'
        DELETE FROM tracker_all_time_high
        WHERE sync_type = :sync_type AND symbol = :symbol AND currency = :currency
        SQL;

    private ?\PDO $connection = null;
    private bool $anAccessFailureWasAlreadyLogged = false;

    public function __construct(
        private readonly string $databaseFilePath,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function readAllTimeHigh(string $syncType, string $symbol, string $currency): ?AllTimeHigh
    {
        $storedCurrency = self::normaliseCurrency($currency);

        try {
            return $this->selectAllTimeHigh($this->connect(), $syncType, $symbol, $storedCurrency);
        } catch (\PDOException $storageError) {
            $this->logAccessFailureOncePerProcess($storageError);

            return null;
        }
    }

    /**
     * Raises the stored high, and only if the given price is strictly higher than the stored one.
     * The caller is the one that knows when the price was reached, so reachedAt travels in the
     * record. Returns the resulting high, or null when the storage is unreachable.
     */
    public function raiseTo(AllTimeHigh $allTimeHigh): ?AllTimeHigh
    {
        $storedCurrency = self::normaliseCurrency($allTimeHigh->currency);

        try {
            $connection = $this->connect();

            $raise = $connection->prepare(self::RAISE_STATEMENT);
            $raise->execute([
                'sync_type' => $allTimeHigh->syncType,
                'symbol' => $allTimeHigh->symbol,
                'currency' => $storedCurrency,
                'price' => $allTimeHigh->price,
                'reached_at' => $allTimeHigh->reachedAt?->format(\DateTimeInterface::RFC3339),
                'observed_at' => $allTimeHigh->observedAt->format(\DateTimeInterface::RFC3339),
            ]);

            return $this->selectAllTimeHigh($connection, $allTimeHigh->syncType, $allTimeHigh->symbol, $storedCurrency);
        } catch (\PDOException $storageError) {
            $this->logAccessFailureOncePerProcess($storageError);

            return null;
        }
    }

    /**
     * Removes the stored high, so that the next syncs rebuild it. Backs the --reset option of the
     * catch-up command. Returns false only when the storage is unreachable: forgetting an asset
     * that was never stored is a success.
     */
    public function forget(string $syncType, string $symbol, string $currency): bool
    {
        try {
            $delete = $this->connect()->prepare(self::DELETE_STATEMENT);
            $delete->execute([
                'sync_type' => $syncType,
                'symbol' => $symbol,
                'currency' => self::normaliseCurrency($currency),
            ]);

            return true;
        } catch (\PDOException $storageError) {
            $this->logAccessFailureOncePerProcess($storageError);

            return false;
        }
    }

    private function selectAllTimeHigh(\PDO $connection, string $syncType, string $symbol, string $storedCurrency): ?AllTimeHigh
    {
        $select = $connection->prepare(self::SELECT_STATEMENT);
        $select->execute([
            'sync_type' => $syncType,
            'symbol' => $symbol,
            'currency' => $storedCurrency,
        ]);

        $storedRow = $select->fetch();
        if (!\is_array($storedRow)) {
            return null;
        }

        $price = self::readFloat($storedRow, 'price');
        $observedAt = self::readInstant($storedRow, 'observed_at');
        if (null === $price || null === $observedAt) {
            return null;
        }

        return new AllTimeHigh(
            $syncType,
            $symbol,
            $storedCurrency,
            $price,
            self::readInstant($storedRow, 'reached_at'),
            $observedAt,
        );
    }

    /**
     * Opened on the first call only, so that building the container creates no file.
     */
    private function connect(): \PDO
    {
        if (null !== $this->connection) {
            return $this->connection;
        }

        $this->createContainingDirectory();

        $connection = new \PDO('sqlite:'.$this->databaseFilePath, options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        // The scheduler consumer and the catch-up command write to the same file: WAL lets one read
        // while the other writes, and the timeout covers the moment the journal is handed over.
        $connection->exec('PRAGMA journal_mode = WAL');
        $connection->exec('PRAGMA busy_timeout = '.self::BUSY_TIMEOUT_IN_MILLISECONDS);
        $connection->exec(self::CREATE_TABLE_STATEMENT);

        $this->connection = $connection;

        return $connection;
    }

    /**
     * A directory that cannot be created is not reported here: opening the database right after
     * fails on its own and every caller already turns that failure into a degraded answer.
     */
    private function createContainingDirectory(): void
    {
        $directoryPath = \dirname($this->databaseFilePath);
        if (is_dir($directoryPath)) {
            return;
        }

        @mkdir($directoryPath, self::DATABASE_DIRECTORY_MODE, true);
    }

    /**
     * Every tracked asset hits this storage a few times every five minutes, so a broken file would
     * write hundreds of warnings an hour. The consumer restarts every hour, which arms it again.
     */
    private function logAccessFailureOncePerProcess(\PDOException $storageError): void
    {
        if ($this->anAccessFailureWasAlreadyLogged) {
            return;
        }

        $this->anAccessFailureWasAlreadyLogged = true;

        $this->logger->warning('The all-time high storage could not be reached', [
            'database' => $this->databaseFilePath,
            'error' => $storageError->getMessage(),
        ]);
    }

    /**
     * pixelcast.yaml writes "eur" where a source serves "EUR": without this the same asset would
     * hold two rows.
     */
    private static function normaliseCurrency(string $currency): string
    {
        return mb_strtoupper($currency);
    }

    /**
     * @param array<array-key, mixed> $storedRow
     */
    private static function readFloat(array $storedRow, string $column): ?float
    {
        $storedValue = $storedRow[$column] ?? null;

        return is_numeric($storedValue) ? (float) $storedValue : null;
    }

    /**
     * @param array<array-key, mixed> $storedRow
     */
    private static function readInstant(array $storedRow, string $column): ?\DateTimeImmutable
    {
        $storedValue = $storedRow[$column] ?? null;
        if (!\is_string($storedValue)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($storedValue);
        } catch (\DateMalformedStringException) {
            return null;
        }
    }
}
