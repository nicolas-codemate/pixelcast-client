<?php

declare(strict_types=1);

namespace App\Command;

use App\Client\DeviceBaseUrl;
use App\Client\Reachability\DeviceReachabilityProbe;
use App\Client\Reachability\DeviceReachabilityStatus;
use App\Client\State\DeviceStateSourceFactory;
use App\Domain\AppDomain;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:device:dump',
    description: 'Dumps the raw device state as JSON',
)]
final class DeviceDumpCommand extends Command
{
    public function __construct(
        #[Autowire('%env(default::PIXELCAST_DEVICE_BASE_URL)%')]
        private readonly ?string $deviceBaseUrl,
        private readonly DeviceReachabilityProbe $deviceReachabilityProbe,
        private readonly DeviceStateSourceFactory $deviceStateSourceFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'Base URL to read from, overriding PIXELCAST_DEVICE_BASE_URL')
            ->addOption('domain', 'd', InputOption::VALUE_REQUIRED, 'Restrict the dump to a single domain (weather, trackers, ...)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string|null $target */
        $target = $input->getOption('target');
        $baseUrl = DeviceBaseUrl::resolve($target ?? $this->deviceBaseUrl);

        $reachability = $this->deviceReachabilityProbe->probe($baseUrl);
        if (DeviceReachabilityStatus::Reachable !== $reachability->status) {
            $io->error(\sprintf('Target %s is %s.', $baseUrl, strtolower($reachability->displayLabel)));

            return Command::FAILURE;
        }

        /** @var string|null $requestedDomain */
        $requestedDomain = $input->getOption('domain');
        if (null !== $requestedDomain && null === AppDomain::tryFrom($requestedDomain)) {
            $io->error(\sprintf(
                'Unknown domain "%s". Available: %s.',
                $requestedDomain,
                implode(', ', array_column(AppDomain::cases(), 'value')),
            ));

            return Command::INVALID;
        }

        $targetSelection = $this->deviceStateSourceFactory->createForTarget($baseUrl);
        $stateSource = $targetSelection->stateSource;

        if ($output->isVerbose()) {
            // The detection note goes to the error stream so the standard output stays pipeable JSON.
            $io->getErrorStyle()->comment(\sprintf(
                'Reading %s as a %s target.',
                $baseUrl,
                $targetSelection->targetKind->value,
            ));
        }

        $payloads = [];
        foreach ($stateSource->snapshot() as $domainKey => $domainState) {
            if (null === $requestedDomain || $requestedDomain === $domainKey) {
                $payloads[$domainKey] = $domainState->payload;
            }
        }

        $readError = $stateSource->reachabilityError();
        if (null !== $readError) {
            $explanation = [\sprintf('Target %s answered on the network but not as a PixelCast device.', $baseUrl)];
            if (null !== $targetSelection->inspectorProbeError) {
                $explanation[] = \sprintf('/__inspect: %s', $targetSelection->inspectorProbeError);
            }
            $explanation[] = \sprintf('REST API: %s', $readError);

            $io->error($explanation);

            return Command::FAILURE;
        }

        $output->writeln((string) json_encode(
            $payloads,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        ));

        return Command::SUCCESS;
    }
}
