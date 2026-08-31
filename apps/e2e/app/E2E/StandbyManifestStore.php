<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\StandbyIdentity;
use RuntimeException;

/**
 * The promoted standby generation and its recorded siblings, under
 * `<primary>/.e2e/standby/`. Generations a live topology still runs on are
 * read from the Incus inventory, never from a ledger.
 *
 * @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods Manifest persistence validates each exact lifecycle state and namespace.
 */
final readonly class StandbyManifestStore
{
    public function __construct(
        private AtomicJsonStore $store,
        private StatePaths $paths,
        private IncusHost $host,
        private StandbyIdentity $identity,
    ) {}

    public function promoted(): ?StandbyGeneration
    {
        $value = $this->store->read('standby/promoted.json');

        if ($value === null) {
            return null;
        }

        $generation = StandbyGeneration::fromArray($value);
        $this->assertMatchesIdentity($generation, allowUnbound: true);

        return $generation;
    }

    public function promote(StandbyGeneration $generation): void
    {
        $this->assertMatchesIdentity($generation);
        $this->store->write('standby/promoted.json', $generation->toArray());
    }

    public function record(StandbyGeneration $generation): void
    {
        $this->assertMatchesIdentity($generation);
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
            $this->assertMatchesIdentity($generation, allowUnbound: true);
            if ($generation->id !== $id) {
                throw new RuntimeException('A standby generation path does not match its identity.');
            }
            $generations[] = $generation;
        }

        return $generations;
    }

    /** @return list<StandbyGeneration> */
    public function ownedRecorded(): array
    {
        return array_values(array_filter(
            $this->recorded(),
            fn (StandbyGeneration $generation): bool => $generation->standbyNamespace === $this->identity->namespace,
        ));
    }

    public function assertOwned(): void
    {
        $promoted = $this->promoted();
        if ($promoted !== null) {
            $this->assertMatchesIdentity($promoted);
        }
        foreach ($this->recorded() as $generation) {
            $this->assertMatchesIdentity($generation);
        }
    }

    /** @return list<StandbyGeneration> */
    public function prunable(StandbyGeneration $current): array
    {
        $recorded = $this->ownedRecorded();
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

    public function forget(StandbyGeneration $generation): void
    {
        $this->assertMatchesIdentity($generation);
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

    private function assertMatchesIdentity(StandbyGeneration $generation, bool $allowUnbound = false): void
    {
        if ($allowUnbound && $generation->standbyNamespace === null) {
            return;
        }
        if ($generation->standbyNamespace === $this->identity->namespace) {
            return;
        }

        $owner = self::namespaceLabel($generation->standbyNamespace);
        $configured = self::namespaceLabel($this->identity->namespace);

        throw new RuntimeException(
            "Standby manifest namespace {$owner} does not match configured standby namespace {$configured}.",
        );
    }

    private static function namespaceLabel(?string $namespace): string
    {
        return match ($namespace) {
            null => 'unbound',
            '' => 'primary',
            default => $namespace,
        };
    }
}
