<?php

declare(strict_types=1);

namespace App\Console\Commands\TopologySnapshot;

use App\E2E\LaravelReleaseResolver;
use App\E2E\PreparedStateFingerprint;
use App\E2E\TopologySnapshotManifestStore;
use Illuminate\Console\Command;
use Throwable;

final class FingerprintCommand extends Command
{
    #[\Override]
    protected $signature = 'topology-snapshot:fingerprint {--main-sha=HEAD} {--json}';
    #[\Override]
    protected $description = 'Compute the desired prepared topology snapshot fingerprint';

    public function handle(
        PreparedStateFingerprint $fingerprints,
        TopologySnapshotManifestStore $topologySnapshot,
        LaravelReleaseResolver $laravel,
    ): int {
        try {
            $commit = $this->option('main-sha');
            if (! is_string($commit)) {
                throw new \InvalidArgumentException('The main SHA is invalid.');
            }
            $promoted = $topologySnapshot->promoted();
            $structural = $fingerprints->forCommit($commit);
            $release = $promoted?->laravel;
            if ($promoted !== null) {
                if ($promoted->structuralFingerprint !== $structural->value) {
                    $release = null;
                }
            }
            $release ??= $laravel->resolve('>=13.0.0');
            $fingerprint = $fingerprints->withLaravel($structural, $release);
            $payload = ['state' => 'fingerprint', 'fingerprint' => $fingerprint->value];
            $this->line($this->option('json') ? json_encode($payload, JSON_THROW_ON_ERROR) : $fingerprint->value);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
