<?php

declare(strict_types=1);

namespace App\Command;

use App\Claude\ClaudeAuthorizationRequestFactory;
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
