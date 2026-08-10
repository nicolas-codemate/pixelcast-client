<?php

declare(strict_types=1);

namespace App\Tests\Claude;

use App\Claude\ClaudeCredentials;
use App\Claude\ClaudeCredentialsStore;
use App\Claude\Exception\ClaudeCredentialsException;
use App\Claude\StoredClaudeCredentials;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ClaudeCredentialsStoreTest extends TestCase
{
    private const string OBTAINED_AT = '2026-08-10T12:00:00+00:00';
    private const string EXPIRES_AT = '2026-08-10T20:00:00+00:00';

    private string $temporaryDirectory;
    private string $credentialsFilePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/pixelcast-claude-credentials-'.bin2hex(random_bytes(6));
        $this->credentialsFilePath = $this->temporaryDirectory.'/credentials.json';
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->temporaryDirectory);

        parent::tearDown();
    }

    public function testAFirstWriteRoundTripsEveryFieldAndLeavesNoReplacedPair(): void
    {
        $store = $this->createStore();
        $store->save(new StoredClaudeCredentials(self::credentials('first')));

        $reloaded = $store->load();

        self::assertSame('access-first', $reloaded->current->accessToken);
        self::assertSame('refresh-first', $reloaded->current->refreshToken);
        self::assertSame(self::EXPIRES_AT, $reloaded->current->expiresAt->format(\DateTimeInterface::RFC3339));
        self::assertSame(self::OBTAINED_AT, $reloaded->current->obtainedAt->format(\DateTimeInterface::RFC3339));
        self::assertSame(['user:profile', 'user:inference'], $reloaded->current->scopes);
        self::assertNull($reloaded->previous);
    }

    public function testARotationMovesTheCurrentPairIntoTheReplacedSlot(): void
    {
        $store = $this->createStore();
        $store->save(new StoredClaudeCredentials(self::credentials('first')));

        $store->save($store->load()->rotatedTo(self::credentials('second')));

        $reloaded = $store->load();
        self::assertSame('access-second', $reloaded->current->accessToken);
        self::assertSame('access-first', $reloaded->previous?->accessToken);
    }

    public function testAThirdWriteDropsTheOldestPairSoTheFileNeverHoldsThree(): void
    {
        $store = $this->createStore();
        $store->save(new StoredClaudeCredentials(self::credentials('first')));
        $store->save($store->load()->rotatedTo(self::credentials('second')));
        $store->save($store->load()->rotatedTo(self::credentials('third')));

        $reloaded = $store->load();
        self::assertSame('access-third', $reloaded->current->accessToken);
        self::assertSame('access-second', $reloaded->previous?->accessToken);

        $decodedFile = $this->decodedCredentialsFile();
        self::assertSame(['version', 'current', 'previous', 'unusableSince'], array_keys($decodedFile));
    }

    public function testTheUnusableMarkRoundTripsAndLeavesBothPairsReadable(): void
    {
        $store = $this->createStore();
        $markedAt = new \DateTimeImmutable('2026-08-10T14:30:00+00:00');
        $store->save(new StoredClaudeCredentials(self::credentials('first')));
        $store->save($store->load()->rotatedTo(self::credentials('second'))->markedUnusableAt($markedAt));

        $reloaded = $store->load();

        self::assertTrue($reloaded->isUnusable());
        self::assertEquals($markedAt, $reloaded->unusableSince);
        self::assertSame('access-second', $reloaded->current->accessToken);
        self::assertSame('access-first', $reloaded->previous?->accessToken);
    }

    public function testAFileWrittenBeforeTheMarkExistedReadsAsUsable(): void
    {
        $store = $this->createStore();
        $store->save(new StoredClaudeCredentials(self::credentials('first')));

        $decodedFile = $this->decodedCredentialsFile();
        unset($decodedFile['unusableSince']);
        file_put_contents($this->credentialsFilePath, json_encode($decodedFile, \JSON_THROW_ON_ERROR));

        self::assertFalse($store->load()->isUnusable());
    }

    public function testAnUnreadableUnusableMarkIsRefusedRatherThanTakenAsHealthy(): void
    {
        $store = $this->createStore();
        $store->save(new StoredClaudeCredentials(self::credentials('first')));

        $decodedFile = $this->decodedCredentialsFile();
        $decodedFile['unusableSince'] = 'not an instant';
        file_put_contents($this->credentialsFilePath, json_encode($decodedFile, \JSON_THROW_ON_ERROR));

        $this->expectException(ClaudeCredentialsException::class);

        $store->load();
    }

    public function testTheWrittenFileIsReadableByItsOwnerOnly(): void
    {
        $this->createStore()->save(new StoredClaudeCredentials(self::credentials('first')));

        self::assertSame(0o600, fileperms($this->credentialsFilePath) & 0o777);
    }

    public function testASuccessfulWriteLeavesNoTemporaryFileBehind(): void
    {
        $store = $this->createStore();
        $store->save(new StoredClaudeCredentials(self::credentials('first')));
        $store->save($store->load()->rotatedTo(self::credentials('second')));

        self::assertSame([$this->credentialsFilePath], glob($this->temporaryDirectory.'/*'));
    }

    public function testWritingIntoADirectoryThatDoesNotExistYetCreatesIt(): void
    {
        $this->credentialsFilePath = $this->temporaryDirectory.'/nested/deeper/credentials.json';

        $this->createStore()->save(new StoredClaudeCredentials(self::credentials('first')));

        self::assertFileExists($this->credentialsFilePath);
    }

    public function testLoadingAMissingFileNamesThePathItLookedAt(): void
    {
        $this->expectException(ClaudeCredentialsException::class);
        $this->expectExceptionMessage($this->credentialsFilePath);

        $this->createStore()->load();
    }

    public function testATruncatedFileIsRefusedRatherThanReadHalfway(): void
    {
        $this->writeRawCredentialsFile('{"version": 1, "current": {"accessToken": "access-first", "refresh');

        $this->expectException(ClaudeCredentialsException::class);

        $this->createStore()->load();
    }

    #[DataProvider('provideUnreadableFileCases')]
    public function testAFileThatCannotBeTrustedIsRefusedAndNamesWhatIsWrong(string $rawContents, string $expectedMessagePart): void
    {
        $this->writeRawCredentialsFile($rawContents);

        $this->expectException(ClaudeCredentialsException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($expectedMessagePart, '/').'/');

        $this->createStore()->load();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideUnreadableFileCases(): iterable
    {
        $current = '{"accessToken": "a", "refreshToken": "r", "expiresAt": "'.self::EXPIRES_AT.'", "scopes": [], "obtainedAt": "'.self::OBTAINED_AT.'"}';

        yield 'valid JSON that is not an object' => ['"just a string"', 'root object'];
        yield 'unknown version' => ['{"version": 2, "current": '.$current.'}', 'version 2'];
        yield 'missing version' => ['{"current": '.$current.'}', 'version null'];
        yield 'missing current slot' => ['{"version": 1, "previous": null}', '"current"'];
        yield 'missing current refresh token' => [
            '{"version": 1, "current": {"accessToken": "a", "expiresAt": "'.self::EXPIRES_AT.'", "obtainedAt": "'.self::OBTAINED_AT.'"}}',
            'current.refreshToken',
        ];
        yield 'unparseable expiry' => [
            '{"version": 1, "current": {"accessToken": "a", "refreshToken": "r", "expiresAt": "whenever", "obtainedAt": "'.self::OBTAINED_AT.'"}}',
            'current.expiresAt',
        ];
        yield 'replaced slot that is not an object' => ['{"version": 1, "current": '.$current.', "previous": "gone"}', '"previous"'];
        yield 'replaced slot missing its access token' => [
            '{"version": 1, "current": '.$current.', "previous": {"refreshToken": "r", "expiresAt": "'.self::EXPIRES_AT.'", "obtainedAt": "'.self::OBTAINED_AT.'"}}',
            'previous.accessToken',
        ];
    }

    public function testTheOnDiskShapeIsTheDocumentedOne(): void
    {
        $store = $this->createStore();
        $store->save(new StoredClaudeCredentials(self::credentials('first')));

        $decodedFile = $this->decodedCredentialsFile();

        self::assertSame(1, $decodedFile['version'] ?? null);
        self::assertArrayHasKey('previous', $decodedFile);
        self::assertNull($decodedFile['previous']);

        $current = $decodedFile['current'] ?? null;
        self::assertIsArray($current);
        self::assertSame(
            ['accessToken', 'refreshToken', 'expiresAt', 'scopes', 'obtainedAt'],
            array_keys($current),
        );
        self::assertSame(self::EXPIRES_AT, $current['expiresAt'] ?? null);
    }

    public function testExistsAnswersWhetherAPairHasEverBeenWritten(): void
    {
        $store = $this->createStore();
        self::assertFalse($store->exists());

        $store->save(new StoredClaudeCredentials(self::credentials('first')));
        self::assertTrue($store->exists());
    }

    private function createStore(): ClaudeCredentialsStore
    {
        return new ClaudeCredentialsStore($this->credentialsFilePath);
    }

    private function writeRawCredentialsFile(string $rawContents): void
    {
        new Filesystem()->dumpFile($this->credentialsFilePath, $rawContents);
    }

    private static function credentials(string $generation): ClaudeCredentials
    {
        return ClaudeCredentials::create(
            accessToken: 'access-'.$generation,
            refreshToken: 'refresh-'.$generation,
            expiresAt: new \DateTimeImmutable(self::EXPIRES_AT),
            scopes: ['user:profile', 'user:inference'],
            obtainedAt: new \DateTimeImmutable(self::OBTAINED_AT),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodedCredentialsFile(): array
    {
        $rawContents = file_get_contents($this->credentialsFilePath);
        if (false === $rawContents) {
            self::fail(\sprintf('The credentials file at "%s" could not be read back.', $this->credentialsFilePath));
        }

        $decodedFile = json_decode($rawContents, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decodedFile)) {
            self::fail('The credentials file does not hold a JSON object.');
        }

        return $decodedFile;
    }
}
