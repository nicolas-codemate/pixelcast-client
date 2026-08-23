<?php

declare(strict_types=1);

namespace App\Command;

use App\Client\Exception\PixelcastClientException;
use App\Client\PixelcastClientInterface;
use App\Config\Exception\PixelCastConfigException;
use App\Config\SyncsConfig;
use App\Config\SyncsConfigLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @template TSection of object
 * @template TPayload of object
 * @template TSnapshot of object
 */
abstract class AbstractDevicePushCommand extends Command
{
    public function __construct(
        protected readonly SyncsConfigLoader $syncsConfigLoader,
        protected readonly PixelcastClientInterface $pixelcastClient,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $syncsConfig = $this->syncsConfigLoader->load();
        } catch (PixelCastConfigException $unusableConfig) {
            $io->error($unusableConfig->getMessage());

            return Command::INVALID;
        }

        $section = $this->sectionFromConfig($syncsConfig);

        if (null === $section) {
            $io->error([
                \sprintf('pixelcast.yaml declares no "%s" section, so there is no %s to push.', $this->sectionKey(), $this->missingSectionNoun()),
                'pixelcast.yaml.dist carries the shape of the section.',
            ]);

            return Command::INVALID;
        }

        try {
            $payload = $this->toPayload($section);
        } catch (PixelCastConfigException $unusableSection) {
            $io->error($unusableSection->getMessage());

            return Command::INVALID;
        }

        try {
            $this->pushPayload($payload);
        } catch (PixelcastClientException $pushFailure) {
            $io->error(\sprintf('The device did not take the %s: %s', $this->pushedThingName(), $pushFailure->getMessage()));

            return Command::FAILURE;
        }

        try {
            $snapshot = $this->readBack();
        } catch (PixelcastClientException $readBackFailure) {
            $io->warning([
                \sprintf('The push of the %s went through, but reading the device back failed: %s', $this->pushedThingName(), $readBackFailure->getMessage()),
                'Nothing says the device did not take the push. Run the command again to see what it holds.',
            ]);

            return Command::SUCCESS;
        }

        $this->report($io, $snapshot);

        return Command::SUCCESS;
    }

    abstract protected function sectionKey(): string;

    abstract protected function missingSectionNoun(): string;

    abstract protected function pushedThingName(): string;

    /**
     * @return TSection|null
     */
    abstract protected function sectionFromConfig(SyncsConfig $syncsConfig): ?object;

    /**
     * @param TSection $section
     *
     * @return TPayload
     */
    abstract protected function toPayload(object $section): object;

    /**
     * @param TPayload $payload
     */
    abstract protected function pushPayload(object $payload): void;

    /**
     * @return TSnapshot
     */
    abstract protected function readBack(): object;

    /**
     * @param TSnapshot $snapshot
     */
    abstract protected function report(SymfonyStyle $io, object $snapshot): void;
}
