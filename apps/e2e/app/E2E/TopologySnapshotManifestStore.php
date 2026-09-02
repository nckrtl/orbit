<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\TopologySnapshotGeneration;
use RuntimeException;

/**
 * The promoted topology snapshot generation and its recorded siblings, under
 * `<primary>/.e2e/topology-snapshot/`. Generations a live topology still runs on are
 * read from the Incus inventory, never from a ledger.
 *
 * @mago-expect lint:cyclomatic-complexity,kan-defect Manifest persistence validates each exact lifecycle state.
 */
final readonly class TopologySnapshotManifestStore
{
    private const array STATE_DIRECTORIES = ['topology-snapshot', 'standby'];

    public function __construct(
        private AtomicJsonStore $store,
        private StatePaths $paths,
        private IncusHost $host,
        private string $stateDirectory = 'topology-snapshot',
    ) {
        if (! in_array($stateDirectory, self::STATE_DIRECTORIES, true)) {
            throw new \InvalidArgumentException('The topology snapshot state directory is invalid.');
        }
    }

    public function promoted(): ?TopologySnapshotGeneration
    {
        $value = $this->store->read($this->statePath('promoted.json'));

        return $value === null ? null : TopologySnapshotGeneration::fromArray($value);
    }

    public function promote(TopologySnapshotGeneration $generation): void
    {
        $this->store->write($this->statePath('promoted.json'), $generation->toArray());
    }

    public function record(TopologySnapshotGeneration $generation): void
    {
        $this->store->write($this->statePath("generations/{$generation->id}.json"), $generation->toArray());
    }

    /** @return list<TopologySnapshotGeneration> */
    public function recorded(): array
    {
        $generations = [];
        foreach ($this->manifestFiles($this->statePath('generations')) as $file) {
            $id = pathinfo($file, PATHINFO_FILENAME);
            $value = $this->store->read($this->statePath('generations/'.basename($file)));
            if ($value === null) {
                throw new RuntimeException('A topology snapshot generation disappeared during inventory.');
            }
            $generation = TopologySnapshotGeneration::fromArray($value);
            if ($generation->id !== $id) {
                throw new RuntimeException('A topology snapshot generation path does not match its identity.');
            }
            $generations[] = $generation;
        }

        return $generations;
    }

    /** @return list<TopologySnapshotGeneration> */
    public function prunable(TopologySnapshotGeneration $current): array
    {
        $recorded = $this->recorded();
        $protected = [$current->id];
        if ($current->previousGenerationId !== null) {
            $protected[] = $current->previousGenerationId;
        }
        foreach ($this->host->harnessInstanceMetadata() as $metadata) {
            $generation = $metadata['user.orbit.e2e.generation'] ?? null;
            if (is_string($generation) && $generation !== '') {
                $protected[] = $generation;
            }
        }

        $prunable = [];
        foreach ($recorded as $generation) {
            if (! in_array($generation->id, $protected, true)) {
                $prunable[] = $generation;
            }
        }

        return $prunable;
    }

    public function forget(TopologySnapshotGeneration $generation): void
    {
        $file = $this->paths->path($this->statePath("generations/{$generation->id}.json"));
        if (! is_file($file) || is_link($file) || ! unlink($file)) {
            throw new RuntimeException('Unable to remove the exact topology snapshot generation manifest.');
        }
    }

    /** Forget every manifest that named resources removed by explicit recovery. */
    public function forgetAll(): void
    {
        foreach ($this->recorded() as $generation) {
            $this->forget($generation);
        }
        $this->store->delete($this->statePath('promoted.json'));
        $this->store->delete($this->statePath('corrupt.json'));
    }

    /** @return list<string> */
    private function manifestFiles(string $collection): array
    {
        $directory = $this->paths->path($collection);
        if (! file_exists($directory)) {
            return [];
        }
        if (! is_dir($directory) || is_link($directory) || ! is_readable($directory)) {
            throw new RuntimeException('A manifest collection cannot be inspected.');
        }

        $files = glob($directory.'/*.json');
        if ($files === false) {
            throw new RuntimeException('A manifest collection cannot be inspected.');
        }
        sort($files, SORT_STRING);

        return $files;
    }

    private function statePath(string $relative): string
    {
        return $this->stateDirectory.'/'.$relative;
    }
}
