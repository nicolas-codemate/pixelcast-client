<?php

declare(strict_types=1);

namespace App\Command;

use App\Scheduler\SyncMessageRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:sync',
    description: 'Dispatches a sync message on the bus, without waiting for the scheduler',
)]
final class SyncCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly SyncMessageRegistry $syncMessageRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::OPTIONAL, 'Sync type to trigger; omit to pick one interactively')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Dispatch every registered sync type');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $syncMessages = $this->syncMessageRegistry->syncMessages();

        /** @var bool $dispatchAll */
        $dispatchAll = $input->getOption('all');
        /** @var string|null $requestedType */
        $requestedType = $input->getArgument('type');

        if ($dispatchAll && null !== $requestedType) {
            $io->error('Pass either a sync type or --all, not both.');

            return Command::INVALID;
        }

        if (null !== $requestedType && !isset($syncMessages[$requestedType])) {
            $io->error(\sprintf(
                'Unknown sync type "%s". Available: %s.',
                $requestedType,
                [] === $syncMessages ? 'none' : implode(', ', array_keys($syncMessages)),
            ));

            return Command::INVALID;
        }

        if (!$dispatchAll && null === $requestedType && !$input->isInteractive()) {
            $io->error('No sync type given. Pass a type argument or --all when running non-interactively.');

            return Command::INVALID;
        }

        if ([] === $syncMessages) {
            $io->warning('No sync type is registered, nothing to dispatch.');

            return Command::SUCCESS;
        }

        if ($dispatchAll) {
            $typesToDispatch = array_keys($syncMessages);
        } elseif (null !== $requestedType) {
            $typesToDispatch = [$requestedType];
        } else {
            /** @var string $chosenType */
            $chosenType = $io->choice('Which sync do you want to run?', array_keys($syncMessages));
            $typesToDispatch = [$chosenType];
        }

        foreach ($typesToDispatch as $typeToDispatch) {
            $message = $syncMessages[$typeToDispatch];
            $io->writeln(\sprintf('Dispatching %s (%s)', $typeToDispatch, $message::class));
            $this->messageBus->dispatch($message);
        }

        return Command::SUCCESS;
    }
}
