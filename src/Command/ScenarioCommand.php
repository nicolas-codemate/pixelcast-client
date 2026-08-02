<?php

declare(strict_types=1);

namespace App\Command;

use App\Scenario\ScenarioCatalog;
use App\Scenario\ScenarioDefinition;
use App\Scenario\ScenarioDispatcher;
use App\Scenario\ScenarioResultFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:scenario',
    description: 'Sends a predefined payload to the configured PixelCast target',
)]
final class ScenarioCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $appEnvironment,
        private readonly ScenarioCatalog $scenarioCatalog,
        private readonly ScenarioDispatcher $scenarioDispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('scenario', InputArgument::OPTIONAL, 'Scenario id to send; omit to list all scenarios')
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'Base URL to send to, overriding PIXELCAST_DEVICE_BASE_URL');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $includeSimulatorOnly = 'dev' === $this->appEnvironment;

        /** @var string|null $requestedId */
        $requestedId = $input->getArgument('scenario');
        if (null === $requestedId) {
            $this->listScenarios($io, $this->scenarioCatalog->all($includeSimulatorOnly));

            return Command::SUCCESS;
        }

        $scenario = $this->scenarioCatalog->findById($requestedId, $includeSimulatorOnly);
        if (null === $scenario) {
            $io->error(\sprintf('Unknown scenario "%s". Run without argument to see the available ids.', $requestedId));

            return Command::INVALID;
        }

        /** @var string|null $target */
        $target = $input->getOption('target');
        $result = $this->scenarioDispatcher->dispatch($scenario, $target);
        $io->writeln(ScenarioResultFormatter::format($result));

        return $result->success ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param list<ScenarioDefinition> $scenarios
     */
    private function listScenarios(SymfonyStyle $io, array $scenarios): void
    {
        $io->table(
            ['id', 'method', 'path', 'description'],
            array_map(
                static fn (ScenarioDefinition $scenario): array => [
                    $scenario->id,
                    $scenario->httpMethod,
                    $scenario->path,
                    $scenario->description,
                ],
                $scenarios,
            ),
        );
    }
}
