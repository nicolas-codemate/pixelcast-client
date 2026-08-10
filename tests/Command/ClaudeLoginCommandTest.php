<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Claude\ClaudeCredentials;
use App\Claude\ClaudeCredentialsStore;
use App\Claude\StoredClaudeCredentials;
use App\Command\ClaudeLoginCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class ClaudeLoginCommandTest extends TestCase
{
    private const string NOW = '2026-08-10T12:00:00+00:00';
    private const string CLI_ACCESS_TOKEN = 'access-token-the-cli-holds';
    private const string CLI_REFRESH_TOKEN = 'refresh-token-the-cli-holds';
    private const int CLI_EXPIRY_IN_MILLISECONDS = 1786392000000;
    private const string CLI_EXPIRY = '2026-08-10T20:00:00+00:00';
    private const string PREEXISTING_ACCESS_TOKEN = 'access-token-already-on-disk';
    private const string PREEXISTING_REFRESH_TOKEN = 'refresh-token-already-on-disk';

    private string $temporaryDirectory;
    private string $credentialsFilePath;
    private string $homeDirectory;
    private string|false $terminalWidthBeforeTheTest;
    private string|false $homeDirectoryBeforeTheTest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/pixelcast-claude-login-'.bin2hex(random_bytes(6));
        $this->credentialsFilePath = $this->temporaryDirectory.'/credentials.json';
        $this->homeDirectory = $this->temporaryDirectory.'/home';

        // Console blocks wrap at the terminal width, and a width of 80 would fold the messages these
        // assertions read. Pinning it keeps them looking at what the operator is told, not at where
        // the console chose to break a line.
        $this->terminalWidthBeforeTheTest = getenv('COLUMNS');
        putenv('COLUMNS=200');

        // The command looks the session up in the home directory of its own process, so the suite
        // gives it one it owns rather than the one of whoever runs it.
        $this->homeDirectoryBeforeTheTest = getenv('HOME');
        putenv('HOME='.$this->homeDirectory);
    }

    protected function tearDown(): void
    {
        putenv(false === $this->terminalWidthBeforeTheTest ? 'COLUMNS' : 'COLUMNS='.$this->terminalWidthBeforeTheTest);
        putenv(false === $this->homeDirectoryBeforeTheTest ? 'HOME' : 'HOME='.$this->homeDirectoryBeforeTheTest);
        new Filesystem()->remove($this->temporaryDirectory);

        parent::tearDown();
    }

    public function testTheSessionTheClaudeCodeCliWroteBecomesTheFirstPair(): void
    {
        $store = new ClaudeCredentialsStore($this->credentialsFilePath);
        $tester = self::createTester($store);
        $sourceFilePath = $this->writeClaudeCodeFile($this->temporaryDirectory.'/elsewhere/.credentials.json');

        $exitCode = $tester->execute(['--from' => $sourceFilePath]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $stored = $store->load();
        self::assertSame(self::CLI_ACCESS_TOKEN, $stored->current->accessToken);
        self::assertSame(self::CLI_REFRESH_TOKEN, $stored->current->refreshToken);
        self::assertSame(self::CLI_EXPIRY, $stored->current->expiresAt->format(\DateTimeInterface::RFC3339));
        self::assertNull($stored->previous);
    }

    public function testWithoutAnOptionTheSessionIsReadFromTheHomeDirectoryOfThisProcess(): void
    {
        $store = new ClaudeCredentialsStore($this->credentialsFilePath);
        $tester = self::createTester($store);
        $this->writeClaudeCodeFile($this->homeDirectory.'/.claude/.credentials.json');

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(self::CLI_ACCESS_TOKEN, $store->load()->current->accessToken);
    }

    public function testTheSuccessOutputNamesTheExpiryAndForbidsRunningTheCliAgainWithoutShowingATokenAtAll(): void
    {
        $tester = self::createTester(new ClaudeCredentialsStore($this->credentialsFilePath));
        $this->writeClaudeCodeFile($this->homeDirectory.'/.claude/.credentials.json');

        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString($this->credentialsFilePath, $display);
        self::assertStringContainsString(self::CLI_EXPIRY, $display);
        self::assertStringContainsString('Do not run the Claude Code CLI on this host again', $display);

        foreach ([self::CLI_ACCESS_TOKEN, self::CLI_REFRESH_TOKEN] as $token) {
            self::assertStringNotContainsString($token, $display);
        }
    }

    public function testAPairAlreadyOnDiskIsLeftAloneAndTheRemedyIsNamed(): void
    {
        $store = $this->storeHoldingAPreexistingPair();
        $tester = self::createTester($store);
        $this->writeClaudeCodeFile($this->homeDirectory.'/.claude/.credentials.json');

        $exitCode = $tester->execute([]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('--force', $tester->getDisplay());
        self::assertSame(self::PREEXISTING_ACCESS_TOKEN, $store->load()->current->accessToken);
    }

    public function testForceReplacesThePairThatWasThere(): void
    {
        $store = $this->storeHoldingAPreexistingPair();
        $tester = self::createTester($store);
        $this->writeClaudeCodeFile($this->homeDirectory.'/.claude/.credentials.json');

        $exitCode = $tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $stored = $store->load();
        self::assertSame(self::CLI_ACCESS_TOKEN, $stored->current->accessToken);
        self::assertNull($stored->previous);
    }

    public function testASessionThatIsNotThereIsReportedWithThePathThatWasLookedAt(): void
    {
        $tester = self::createTester(new ClaudeCredentialsStore($this->credentialsFilePath));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString($this->homeDirectory.'/.claude/.credentials.json', $tester->getDisplay());
        self::assertFileDoesNotExist($this->credentialsFilePath);
    }

    public function testASourceFileThatIsNotJsonIsReportedRatherThanWritten(): void
    {
        $sourceFilePath = $this->temporaryDirectory.'/not-json/.credentials.json';
        new Filesystem()->dumpFile($sourceFilePath, 'this is not JSON at all');
        $tester = self::createTester(new ClaudeCredentialsStore($this->credentialsFilePath));

        $exitCode = $tester->execute(['--from' => $sourceFilePath]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('not readable JSON', $tester->getDisplay());
        self::assertFileDoesNotExist($this->credentialsFilePath);
    }

    public function testASourceFileCarryingNoSessionIsReportedRatherThanWritten(): void
    {
        $sourceFilePath = $this->temporaryDirectory.'/no-session/.credentials.json';
        new Filesystem()->dumpFile($sourceFilePath, json_encode(['somethingElse' => []], \JSON_THROW_ON_ERROR));
        $tester = self::createTester(new ClaudeCredentialsStore($this->credentialsFilePath));

        $exitCode = $tester->execute(['--from' => $sourceFilePath]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('claudeAiOauth', $tester->getDisplay());
        self::assertFileDoesNotExist($this->credentialsFilePath);
    }

    public function testAProcessWithoutAHomeDirectoryIsToldToNameTheFileItself(): void
    {
        putenv('HOME');
        $tester = self::createTester(new ClaudeCredentialsStore($this->credentialsFilePath));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('--from', $tester->getDisplay());
        self::assertFileDoesNotExist($this->credentialsFilePath);
    }

    public function testADirectoryThatCannotBeWrittenIntoIsReportedBeforeTheSessionIsRead(): void
    {
        $readOnlyDirectory = $this->temporaryDirectory.'/read-only';
        new Filesystem()->mkdir($readOnlyDirectory);
        chmod($readOnlyDirectory, 0o500);
        clearstatcache(true, $readOnlyDirectory);

        if (is_writable($readOnlyDirectory)) {
            self::markTestSkipped('The permission bits of a directory do not stop the user this suite runs as.');
        }

        $tester = self::createTester(new ClaudeCredentialsStore($readOnlyDirectory.'/claude/credentials.json'));
        $this->writeClaudeCodeFile($this->homeDirectory.'/.claude/.credentials.json');

        $exitCode = $tester->execute([]);

        chmod($readOnlyDirectory, 0o700);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString($readOnlyDirectory, $tester->getDisplay());
        self::assertStringNotContainsString('Do not run the Claude Code CLI', $tester->getDisplay());
    }

    private function storeHoldingAPreexistingPair(): ClaudeCredentialsStore
    {
        $store = new ClaudeCredentialsStore($this->credentialsFilePath);
        $store->save(new StoredClaudeCredentials(ClaudeCredentials::create(
            accessToken: self::PREEXISTING_ACCESS_TOKEN,
            refreshToken: self::PREEXISTING_REFRESH_TOKEN,
            expiresAt: new \DateTimeImmutable('2026-08-10T20:00:00+00:00'),
            scopes: ['user:profile'],
            obtainedAt: new \DateTimeImmutable(self::NOW),
        )));

        return $store;
    }

    private function writeClaudeCodeFile(string $filePath): string
    {
        new Filesystem()->dumpFile($filePath, json_encode([
            'claudeAiOauth' => [
                'accessToken' => self::CLI_ACCESS_TOKEN,
                'refreshToken' => self::CLI_REFRESH_TOKEN,
                'expiresAt' => self::CLI_EXPIRY_IN_MILLISECONDS,
                'scopes' => ['user:profile', 'user:sessions:claude_code'],
            ],
        ], \JSON_THROW_ON_ERROR));

        return $filePath;
    }

    private static function createTester(ClaudeCredentialsStore $store): CommandTester
    {
        return new CommandTester(new ClaudeLoginCommand($store));
    }
}
