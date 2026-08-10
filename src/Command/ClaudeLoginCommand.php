<?php

declare(strict_types=1);

namespace App\Command;

use App\Claude\ClaudeAuthorizationRequestFactory;
use App\Claude\ClaudeCredentials;
use App\Claude\ClaudeCredentialsStore;
use App\Claude\ClaudeOAuthClient;
use App\Claude\Exception\ClaudeCredentialsException;
use App\Claude\Exception\ClaudeOAuthException;
use App\Claude\StoredClaudeCredentials;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Authorises the Claude session this host owns, once, by hand.
 *
 * There is no way to do this without a browser: the authorization server refuses the device-code
 * grant to the Claude Code client, so the operator opens a URL on whatever machine has a browser
 * and pastes back the line the approval displays. Everything after that first pair is automatic —
 * the poller refreshes it on its own.
 */
#[AsCommand(
    name: 'app:claude:login',
    description: 'Authorises the Claude session this host owns and writes its credentials file',
)]
final class ClaudeLoginCommand extends Command
{
    public function __construct(
        private readonly ClaudeOAuthClient $claudeOAuthClient,
        private readonly ClaudeCredentialsStore $credentialsStore,
        private readonly ClaudeAuthorizationRequestFactory $authorizationRequestFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Replace the credentials file that is already there');
        $this->addOption('import', null, InputOption::VALUE_REQUIRED, 'Adopt the session from a Claude Code credentials file instead of approving one in a browser');
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

        /** @var string|null $claudeCodeFilePath */
        $claudeCodeFilePath = $input->getOption('import');
        if (null !== $claudeCodeFilePath) {
            return $this->adoptClaudeCodeSession($io, $claudeCodeFilePath);
        }

        $authorizationRequest = $this->authorizationRequestFactory->create();

        $io->section('Approve this host in a browser');
        $io->writeln([
            'Open the URL below on any machine that has a browser, sign in with the account this host',
            'should report the usage of, and approve. The page then displays a line of the form',
            '"<code>#<state>": copy it whole and paste it back here.',
            '',
            $authorizationRequest->authorizationUrl(),
            '',
        ]);

        /** @var string|null $approvalResult */
        $approvalResult = $io->ask('Paste the line the approval page displayed');

        if (null === $approvalResult || '' === trim($approvalResult)) {
            $io->error('Nothing was pasted, so there is no authorization to exchange.');

            return Command::FAILURE;
        }

        try {
            $authorizationCode = $authorizationRequest->codeFromApprovalResult($approvalResult);
            $credentials = $this->claudeOAuthClient->exchangeAuthorizationCode($authorizationRequest, $authorizationCode);
        } catch (ClaudeOAuthException $authorizationFailure) {
            $io->error($authorizationFailure->getMessage());

            return Command::FAILURE;
        }

        try {
            $this->credentialsStore->save(new StoredClaudeCredentials($credentials));
        } catch (ClaudeCredentialsException $writeFailure) {
            $io->error($writeFailure->getMessage());

            return Command::INVALID;
        }

        $io->success([
            \sprintf('The pair is written to %s.', $this->credentialsStore->credentialsFilePath),
            \sprintf(
                'Its access token expires at %s and is renewed from then on without anyone here.',
                $credentials->expiresAt->format(\DateTimeInterface::RFC3339),
            ),
        ]);

        return Command::SUCCESS;
    }

    /**
     * Takes over the session the Claude Code CLI holds on this host.
     *
     * The authorization server grants the Claude Code scopes to the CLI's own approval flow and to
     * no other: ours comes back with two scopes out of five and the usage endpoint answers 403. So
     * the CLI logs in here once, and the poller adopts what it wrote.
     *
     * From that moment the poller owns the token family and rotates it, which holds only as long as
     * the CLI is not run on this host again — the refresh token rotates, and whichever of the two
     * renews first leaves the other holding a token the server has retired.
     */
    private function adoptClaudeCodeSession(SymfonyStyle $io, string $claudeCodeFilePath): int
    {
        $contents = is_file($claudeCodeFilePath) ? @file_get_contents($claudeCodeFilePath) : false;
        if (false === $contents) {
            $io->error(\sprintf('No readable Claude Code credentials file at "%s".', $claudeCodeFilePath));

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
     * ancestor that already exists. Asking here rather than at the write means an operator learns of
     * a read-only mount before going to a browser, not after spending an authorization code on it.
     */
    private function unwritableCredentialsDirectory(): ?string
    {
        $directoryPath = \dirname($this->credentialsStore->credentialsFilePath);
        while (!is_dir($directoryPath) && \dirname($directoryPath) !== $directoryPath) {
            $directoryPath = \dirname($directoryPath);
        }

        return is_writable($directoryPath) ? null : $directoryPath;
    }
}
