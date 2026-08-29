<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\StandbyGeneration;
use RuntimeException;

/** @mago-expect lint:cyclomatic-complexity Manifest persistence validates each exact lifecycle state. */
final readonly class StandbyManifestStore
{
    public function __construct(
        private AtomicJsonStore $store,
        private StatePaths $paths,
    ) {}

    public function promoted(): ?StandbyGeneration
    {
        $value = $this->store->read('standby/promoted.json');

        return $value === null ? null : StandbyGeneration::fromArray($value);
    }

    public function promote(StandbyGeneration $generation): void
    {
        $this->store->write('standby/promoted.json', $generation->toArray());
    }

    public function record(StandbyGeneration $generation): void
    {
        $this->store->write("standby/generations/{$generation->id}.json", $generation->toArray());
    }

    /** @return list<StandbyGeneration> */
    public function recorded(): array
    {
        $generations = [];
        foreach ($this->manifestFiles('standby/generations') as $file) {
            $id = pathinfo($file, PATHINFO_FILENAME);
            $value = $this->store->read('standby/generations/'.basename($file));
            if ($value === null) {
                throw new RuntimeException('A standby generation disappeared during inventory.');
            }
            $generation = StandbyGeneration::fromArray($value);
            if ($generation->id !== $id) {
                throw new RuntimeException('A standby generation path does not match its identity.');
            }
            $generations[] = $generation;
        }

        return $generations;
    }

    /** @return list<StandbyGeneration> */
    public function prunable(StandbyGeneration $current): array
    {
        $protected = [$current->id];
        if ($current->previousGenerationId !== null) {
            $protected[] = $current->previousGenerationId;
        }

        foreach ($this->manifestFiles('topologies') as $file) {
            $relative = 'topologies/'.basename($file);
            $topology = $this->store->read($relative);
            $generationId = $topology['generation']['id'] ?? null;
            if (! is_string($generationId)) {
                throw new RuntimeException('A topology generation pin is uncertain.');
            }
            $protected[] = $generationId;
        }

        $prunable = [];
        foreach ($this->recorded() as $generation) {
            if (! in_array($generation->id, $protected, true)) {
                $prunable[] = $generation;
            }
        }

        return $prunable;
    }

    public function forget(StandbyGeneration $generation): void
    {
        $file = $this->paths->path("standby/generations/{$generation->id}.json");
        if (! is_file($file) || is_link($file) || ! unlink($file)) {
            throw new RuntimeException('Unable to remove the exact standby generation manifest.');
        }
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
}
