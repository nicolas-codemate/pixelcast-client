<?php

declare(strict_types=1);

namespace App\Command;

use App\Claude\ClaudeCredentials;
use App\Claude\ClaudeCredentialsStore;
use App\Claude\Exception\ClaudeCredentialsException;
use App\Claude\StoredClaudeCredentials;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Takes over the session the Claude Code CLI holds on this host.
 *
 * The session cannot be authorised from here: the authorization server grants the scopes that open
 * the usage endpoint to the CLI's own approval and to no other, so the CLI logs in on the host,
 * once, and this reads the pair it wrote.
 *
 * From that moment the poller owns the token family and rotates it, which holds only as long as the
 * CLI is not run on this host again — the refresh token rotates, and whichever of the two renews
 * first leaves the other holding a token the server has retired.
 */
#[AsCommand(
    name: 'app:claude:login',
    description: 'Adopts the Claude session the Claude Code CLI created on this host',
)]
final class ClaudeLoginCommand extends Command
{
    private const string HOME_DIRECTORY_VARIABLE = 'HOME';
    private const string CLAUDE_CODE_CREDENTIALS_FILE_IN_HOME = '.claude/.credentials.json';

    public function __construct(
        private readonly ClaudeCredentialsStore $credentialsStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Replace the credentials file that is already there');
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Read the Claude Code credentials file at this path instead of the one in the home directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var bool $replaceExistingPair */
        $replaceExistingPair = $input->getOption('force');

        if (!$replaceExistingPair && $this->credentialsStore->exists()) {
            $io->error([
                \sprintf('A Claude credentials file is already there: %s', $this->credentialsStore->credentialsFilePath),
                'Replacing it logs this host out of the session it is currently using. Pass --force to do it anyway.',
            ]);

            return Command::INVALID;
        }

        $unwritableDirectory = $this->unwritableCredentialsDirectory();
        if (null !== $unwritableDirectory) {
            $io->error(\sprintf('The credentials file cannot be written: the directory "%s" is not writable.', $unwritableDirectory));

            return Command::INVALID;
        }

        /** @var string|null $requestedFilePath */
        $requestedFilePath = $input->getOption('from');
        $claudeCodeFilePath = $requestedFilePath ?? self::claudeCodeCredentialsFileInHome();

        if (null === $claudeCodeFilePath) {
            $io->error([
                \sprintf('This process has no "%s" set, so the Claude Code credentials file cannot be located.', self::HOME_DIRECTORY_VARIABLE),
                'Pass --from with the path to it.',
            ]);

            return Command::INVALID;
        }

        return $this->adoptClaudeCodeSession($io, $claudeCodeFilePath);
    }

    private function adoptClaudeCodeSession(SymfonyStyle $io, string $claudeCodeFilePath): int
    {
        $contents = is_file($claudeCodeFilePath) ? @file_get_contents($claudeCodeFilePath) : false;
        if (false === $contents) {
            $io->error([
                \sprintf('No readable Claude Code credentials file at "%s".', $claudeCodeFilePath),
                'Run "claude" where the CLI lives to create the session. When that is the host and this',
                'runs in a container, copy its file into the mounted directory and name it with --from.',
            ]);

            return Command::INVALID;
        }

        try {
            $decodedFile = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
            if (!\is_array($decodedFile)) {
                throw new \JsonException('not an object');
            }

            $credentials = ClaudeCredentials::fromClaudeCodeFile($decodedFile, $claudeCodeFilePath, new \DateTimeImmutable());
            $this->credentialsStore->save(new StoredClaudeCredentials($credentials));
        } catch (\JsonException) {
            $io->error(\sprintf('The Claude Code credentials file at "%s" is not readable JSON.', $claudeCodeFilePath));

            return Command::INVALID;
        } catch (ClaudeCredentialsException $adoptionFailure) {
            $io->error($adoptionFailure->getMessage());

            return Command::INVALID;
        }

        $io->success([
            \sprintf('The session held by %s is now written to %s.', $claudeCodeFilePath, $this->credentialsStore->credentialsFilePath),
            \sprintf('Its access token expires at %s and is renewed from here on.', $credentials->expiresAt->format(\DateTimeInterface::RFC3339)),
            'Do not run the Claude Code CLI on this host again: it would renew the same family and retire this pair.',
        ]);

        return Command::SUCCESS;
    }

    /**
     * The store creates the directory it writes into, so what has to be writable now is the closest
     * ancestor that already exists.
     */
    private function unwritableCredentialsDirectory(): ?string
    {
        $directoryPath = \dirname($this->credentialsStore->credentialsFilePath);
        while (!is_dir($directoryPath) && \dirname($directoryPath) !== $directoryPath) {
            $directoryPath = \dirname($directoryPath);
        }

        return is_writable($directoryPath) ? null : $directoryPath;
    }

    private static function claudeCodeCredentialsFileInHome(): ?string
    {
        $homeDirectory = getenv(self::HOME_DIRECTORY_VARIABLE);

        if (!\is_string($homeDirectory) || '' === $homeDirectory) {
            return null;
        }

        return rtrim($homeDirectory, '/').'/'.self::CLAUDE_CODE_CREDENTIALS_FILE_IN_HOME;
    }
}
