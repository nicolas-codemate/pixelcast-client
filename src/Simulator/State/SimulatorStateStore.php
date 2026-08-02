<?php

declare(strict_types=1);

namespace App\Simulator\State;

use App\Simulator\Logging\RequestLog;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final readonly class SimulatorStateStore
{
    /**
     * @param iterable<ResettableState> $states
     */
    public function __construct(
        #[AutowireIterator('app.simulator_state')]
        private iterable $states,
        private RequestLog $requestLog,
        #[Autowire('%app.simulator.state_file%')]
        private string $stateFilePath,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function load(): void
    {
        $persistedState = $this->readStateFile();

        if (null === $persistedState) {
            return;
        }

        $persistedDomains = PersistedStateReader::payload($persistedState['states'] ?? null) ?? [];

        foreach ($this->states as $state) {
            $persistedDomain = PersistedStateReader::payload($persistedDomains[$state->domainKey()] ?? null);

            if (null !== $persistedDomain) {
                $state->restoreFromPersistence($persistedDomain);
            }
        }

        $this->requestLog->restoreFromPersistence(
            PersistedStateReader::payloadList($persistedState['requestLog'] ?? null),
        );
    }

    public function save(): void
    {
        $exportedDomains = [];
        foreach ($this->states as $state) {
            $exportedDomains[$state->domainKey()] = $state->exportForPersistence();
        }

        $encodedState = json_encode(
            [
                'states' => $exportedDomains,
                'requestLog' => $this->requestLog->exportForPersistence(),
            ],
            \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR,
        );

        $this->filesystem->dumpFile($this->stateFilePath, $encodedState);
    }

    public function purge(): void
    {
        foreach ($this->states as $state) {
            $state->reset();
        }

        $this->requestLog->reset();
        $this->filesystem->remove($this->stateFilePath);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readStateFile(): ?array
    {
        if (!is_file($this->stateFilePath)) {
            return null;
        }

        try {
            $contents = $this->filesystem->readFile($this->stateFilePath);
            $decoded = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        } catch (IOException|\JsonException) {
            return null;
        }

        return PersistedStateReader::payload($decoded);
    }
}
