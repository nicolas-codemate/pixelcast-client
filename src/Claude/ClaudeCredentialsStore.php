<?php

declare(strict_types=1);

namespace App\Claude;

use App\Claude\Exception\ClaudeCredentialsException;

/**
 * Reads and writes the Claude OAuth pair the device owns. Losing that pair costs a manual login on
 * the device, so the write is built to survive a crash or a power cut in the middle of a rotation.
 *
 * On disk:
 *
 *     {
 *       "version": 1,
 *       "current":  {"accessToken": "…", "refreshToken": "…",
 *                    "expiresAt": "2026-08-09T21:40:00+00:00",
 *                    "scopes": ["user:profile"],
 *                    "obtainedAt": "2026-08-09T13:40:00+00:00"},
 *       "previous": null
 *     }
 *
 * `version` turns a future shape change into a migration instead of a crash; an unknown value is
 * refused rather than half-read, and so is any malformed slot. Timestamps are RFC 3339 in UTC
 * because this file is read by a human under duress far more often than by the program, and
 * "2026-08-09T21:40:00+00:00" answers "is this expired?" at a glance. Keys are camelCase, matching
 * how the rest of the repository names persisted keys — this is our format, not Anthropic's.
 *
 * Keeping the replaced pair is best effort. It buys back the case where a rotation only partially
 * applied: a new pair was issued but the write did not land, so the server may still be on the
 * pair we hold. It does NOT recover the case where the server invalidates the old refresh token
 * the instant it issues a new one and the write then fails — both slots on disk are then dead and
 * only a fresh login helps. That window can be made small, never closed.
 */
final readonly class ClaudeCredentialsStore
{
    private const int SUPPORTED_VERSION = 1;
    private const string CURRENT_SLOT = 'current';
    private const string PREVIOUS_SLOT = 'previous';
    private const int CREDENTIALS_FILE_MODE = 0o600;
    private const int CREDENTIALS_DIRECTORY_MODE = 0o700;
    private const string TEMPORARY_FILE_SUFFIX = '.tmp';

    public function __construct(
        public string $credentialsFilePath,
    ) {
    }

    public function exists(): bool
    {
        return is_file($this->credentialsFilePath);
    }

    /**
     * @throws ClaudeCredentialsException
     */
    public function load(): StoredClaudeCredentials
    {
        if (!is_file($this->credentialsFilePath)) {
            throw ClaudeCredentialsException::fileNotFound($this->credentialsFilePath);
        }

        $contents = @file_get_contents($this->credentialsFilePath);
        if (false === $contents) {
            throw ClaudeCredentialsException::fileNotReadable($this->credentialsFilePath);
        }

        try {
            $decodedFile = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $decodingError) {
            throw ClaudeCredentialsException::malformedJson($this->credentialsFilePath, $decodingError);
        }

        if (!\is_array($decodedFile)) {
            throw ClaudeCredentialsException::malformedField($this->credentialsFilePath, 'root object');
        }

        $declaredVersion = $decodedFile['version'] ?? null;
        if (self::SUPPORTED_VERSION !== $declaredVersion) {
            throw ClaudeCredentialsException::unsupportedVersion($this->credentialsFilePath, $declaredVersion, self::SUPPORTED_VERSION);
        }

        $persistedCurrent = $decodedFile[self::CURRENT_SLOT] ?? null;
        if (!\is_array($persistedCurrent)) {
            throw ClaudeCredentialsException::malformedField($this->credentialsFilePath, self::CURRENT_SLOT);
        }

        return new StoredClaudeCredentials(
            ClaudeCredentials::restoreFromPersistence($persistedCurrent, $this->credentialsFilePath, self::CURRENT_SLOT),
            $this->readPreviousSlot($decodedFile[self::PREVIOUS_SLOT] ?? null),
        );
    }

    /**
     * @throws ClaudeCredentialsException
     */
    public function save(StoredClaudeCredentials $storedCredentials): void
    {
        $this->createContainingDirectory();

        $encodedFile = json_encode(
            [
                'version' => self::SUPPORTED_VERSION,
                self::CURRENT_SLOT => $storedCredentials->current->exportForPersistence(),
                self::PREVIOUS_SLOT => $storedCredentials->previous?->exportForPersistence(),
            ],
            \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR,
        );

        $this->writeThroughTemporaryFile($encodedFile);
    }

    /**
     * A slot that is present but unreadable is refused rather than dropped: the file is written by
     * this class alone, so anything else in there means the pair cannot be trusted.
     *
     * @throws ClaudeCredentialsException
     */
    private function readPreviousSlot(mixed $persistedPrevious): ?ClaudeCredentials
    {
        if (null === $persistedPrevious) {
            return null;
        }

        if (!\is_array($persistedPrevious)) {
            throw ClaudeCredentialsException::malformedField($this->credentialsFilePath, self::PREVIOUS_SLOT);
        }

        return ClaudeCredentials::restoreFromPersistence($persistedPrevious, $this->credentialsFilePath, self::PREVIOUS_SLOT);
    }

    /**
     * @throws ClaudeCredentialsException
     */
    private function createContainingDirectory(): void
    {
        $directoryPath = \dirname($this->credentialsFilePath);
        if (is_dir($directoryPath)) {
            return;
        }

        if (!@mkdir($directoryPath, self::CREDENTIALS_DIRECTORY_MODE, true) && !is_dir($directoryPath)) {
            throw ClaudeCredentialsException::directoryNotWritable($directoryPath);
        }
    }

    /**
     * @throws ClaudeCredentialsException
     */
    private function writeThroughTemporaryFile(string $encodedFile): void
    {
        // Same directory as the target, otherwise the rename below crosses a filesystem boundary
        // and stops being atomic.
        $temporaryPath = $this->credentialsFilePath.'.'.bin2hex(random_bytes(8)).self::TEMPORARY_FILE_SUFFIX;

        // "x" fails instead of clobbering, so two writers racing cannot interleave their bytes.
        $temporaryFile = @fopen($temporaryPath, 'x');
        if (false === $temporaryFile) {
            throw ClaudeCredentialsException::writeFailed($this->credentialsFilePath, \sprintf('the temporary file "%s" could not be created', $temporaryPath));
        }

        try {
            // Narrow the permissions before the secret reaches the disk, never after.
            if (!chmod($temporaryPath, self::CREDENTIALS_FILE_MODE)) {
                throw ClaudeCredentialsException::writeFailed($this->credentialsFilePath, 'the temporary file could not be made private');
            }

            if (fwrite($temporaryFile, $encodedFile) !== \strlen($encodedFile)) {
                throw ClaudeCredentialsException::writeFailed($this->credentialsFilePath, 'only part of the pair reached the temporary file');
            }

            // fflush leaves PHP's buffer, fsync leaves the kernel's. Without the second, a power
            // cut can leave a zero-length file behind an otherwise atomic rename.
            if (!fflush($temporaryFile) || !fsync($temporaryFile)) {
                throw ClaudeCredentialsException::writeFailed($this->credentialsFilePath, 'the temporary file could not be flushed to the disk');
            }

            fclose($temporaryFile);

            if (!rename($temporaryPath, $this->credentialsFilePath)) {
                throw ClaudeCredentialsException::writeFailed($this->credentialsFilePath, 'the temporary file could not be renamed over the target');
            }
        } finally {
            if (\is_resource($temporaryFile)) {
                fclose($temporaryFile);
            }

            // Never leave .tmp litter next to a credentials file.
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }

        self::syncDirectoryOf($this->credentialsFilePath);
    }

    /**
     * The rename is atomic, but it only reaches the disk once the containing directory is flushed
     * too. Not every platform lets a process open a directory, and a pair that is written but not
     * yet durable is still better than a failed save, so this step is best effort.
     */
    private static function syncDirectoryOf(string $filePath): void
    {
        $directory = @fopen(\dirname($filePath), 'r');
        if (false === $directory) {
            return;
        }

        @fsync($directory);
        fclose($directory);
    }
}
