<?php

declare(strict_types=1);

use App\E2E\LegacyRetirement;
use App\E2E\State\OperationLock;
use App\E2E\Value\OperationId;

function legacyFixture(): array
{
    $root = temporaryPath('legacy-retirement-', 6);
    mkdir($root.'/sources', 0700, true);
    mkdir($root.'/manifests', 0700, true);
    mkdir($root.'/locks', 0700, true);
    mkdir($root.'/evidence', 0700, true);
    mkdir($root.'/sources/old', 0700);
    file_put_contents($root.'/manifests/old.json', '{}');
    file_put_contents($root.'/locks/old.lock', 'locked');
    file_put_contents($root.'/evidence/proof.json', '{}');

    return [
        'instances' => [
            [
                'name' => 'orbit-template-api',
                'status' => 'STOPPED',
                'metadata' => ['owner' => 'old'],
                'dependencies' => ['legacy-net'],
                'classification' => 'legacy',
                'remote' => 'local',
                'project' => 'default',
            ],
            [
                'name' => 'orbit-e2e-dev-42',
                'status' => 'RUNNING',
                'owner' => 'alice',
                'metadata' => ['owner' => 'old'],
                'dependencies' => ['legacy-net'],
                'classification' => 'legacy',
                'remote' => 'local',
                'project' => 'default',
            ],
            [
                'name' => 'orbit-e2e-topology-snapshot-gateway',
                'status' => 'STOPPED',
                'metadata' => ['owner' => 'orbit-e2e'],
                'dependencies' => [],
                'classification' => 'preserve',
                'remote' => 'local',
                'project' => 'default',
            ],
            [
                'name' => 'database',
                'status' => 'RUNNING',
                'metadata' => ['owner' => 'other'],
                'dependencies' => [],
                'classification' => 'preserve',
                'remote' => 'local',
                'project' => 'default',
            ],
        ],
        'snapshots' => [[
            'name' => 'orbit-template-api/ready',
            'classification' => 'legacy',
            'remote' => 'local',
            'project' => 'default',
        ]],
        'networks' => [
            [
                'name' => 'legacy-net',
                'dependencies' => [],
                'classification' => 'legacy',
                'remote' => 'local',
                'project' => 'default',
            ],
            ['name' => 'shared-net', 'classification' => 'preserve'],
        ],
        'source_paths' => [[
            'path' => $root.'/sources/old',
            'safe_root' => $root.'/sources',
            'classification' => 'legacy',
        ]],
        'manifests' => [[
            'path' => $root.'/manifests/old.json',
            'safe_root' => $root.'/manifests',
            'classification' => 'legacy',
        ]],
        'locks' => [[
            'path' => $root.'/locks/old.lock',
            'safe_root' => $root.'/locks',
            'classification' => 'legacy',
        ]],
        'base_images' => [['name' => 'ubuntu-generic', 'classification' => 'preserve']],
        'pools' => [['name' => 'orbit-e2e', 'identity' => 'pool-uuid-1', 'classification' => 'preserve']],
        'new_namespace' => [['name' => 'orbit-e2e-tst-123-aaaaaaaa-gateway', 'classification' => 'preserve']],
        'evidence' => [[
            'path' => $root.'/evidence/proof.json',
            'identity' => 'proof-1',
            'classification' => 'preserve',
        ]],
    ];
}

function retirementService(
    array &$observed,
    array &$operations,
    string $now,
    ?Closure $observeCurrent = null,
): LegacyRetirement {
    $paths = new \App\E2E\State\StatePaths(temporaryPath('legacy-retirement-lock-', 6));

    return new LegacyRetirement(
        function () use (&$observed): array {
            return $observed;
        },
        function (string $operation, array $resource) use (&$operations, &$observed): void {
            $identity = $resource['name'] ?? $resource['path'];
            $operations[] = [$operation, $identity];
            if ($operation === 'stop') {
                foreach ($observed['instances'] as &$instance) {
                    if ($instance['name'] === $identity) {
                        $instance['status'] = 'STOPPED';
                    }
                }

                return;
            }
            $kind = substr($operation, 7);
            $observed[$kind] = array_values(array_filter(
                $observed[$kind],
                fn (array $item): bool => ($item['name'] ?? $item['path']) !== $identity,
            ));
        },
        fn (): \DateTimeImmutable => new \DateTimeImmutable($now),
        new OperationLock($paths),
        new OperationId(str_repeat('a', 32)),
        $observeCurrent,
    );
}

it('revalidates only recorded resources from live state while resuming quarantine', function () {
    $observed = legacyFixture();
    $operations = [];
    $requests = [];
    $journal = temporaryFile('legacy-journal-');
    chmod($journal, 0600);
    unlink($journal);
    $evidence = temporaryFile('freeze-');
    file_put_contents($evidence, 'frozen');
    chmod($evidence, 0600);
    $initial = crashBeforeMutationRetirementService($observed, $operations, '2026-08-28T10:00:00+00:00', 'stop');
    $inventory = $initial->inventory();
    expect(fn () => $initial->quarantine($inventory, $inventory->sha256(), $evidence, $journal))
        ->toThrow(RuntimeException::class, 'simulated crash');

    $resumed = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00', function (?array $current) use (
        &$requests,
        &$observed,
    ): array {
        $requests[] = $current;

        return $observed;
    });
    $resumed->quarantine($inventory, $inventory->sha256(), $evidence, $journal);

    expect($requests)->not->toBeEmpty()->and($requests[0])->toBeArray()->and($requests[0])->not->toBeEmpty();
    unlink($journal);
    unlink($evidence);
});

it('uses the injected operation ID for the retirement lock', function () {
    $observed = legacyFixture();
    $operations = [];
    $paths = new \App\E2E\State\StatePaths(temporaryPath('legacy-lock-', 4));
    $operation = new OperationId(str_repeat('e', 32));
    $seenOperation = null;
    $evidence = temporaryFile('freeze-');
    file_put_contents($evidence, 'frozen');
    chmod($evidence, 0600);

    $service = new LegacyRetirement(
        function () use (&$observed, &$seenOperation, $paths): array {
            $ownerPath = $paths->path('locks/legacy-retirement.lock');
            if (is_file($ownerPath)) {
                $owner = file_get_contents($ownerPath);
                $seenOperation = is_string($owner) ? json_decode($owner, true)['operation_id'] ?? null : null;
            }

            return $observed;
        },
        function (string $name, array $resource) use (&$operations): void {
            $operations[] = [$name, $resource];
        },
        fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-08-28T10:00:00+00:00'),
        new OperationLock($paths),
        $operation,
    );
    $inventory = $service->inventory();
    $service->quarantine($inventory, $inventory->sha256(), $evidence);

    expect($seenOperation)->toBe($operation->value);
    unlink($evidence);
});

function crashingRetirementService(
    array &$observed,
    array &$operations,
    string $now,
    string $crashOperation,
): LegacyRetirement {
    $crashed = false;
    $paths = new \App\E2E\State\StatePaths(temporaryPath('legacy-retirement-lock-', 6));

    return new LegacyRetirement(
        function () use (&$observed): array {
            return $observed;
        },
        function (string $operation, array $resource) use (&$operations, &$observed, $crashOperation, &$crashed): void {
            $identity = $resource['name'] ?? $resource['path'];
            $operations[] = [$operation, $identity];
            if ($operation === 'stop') {
                foreach ($observed['instances'] as &$instance) {
                    if ($instance['name'] === $identity) {
                        $instance['status'] = 'STOPPED';
                    }
                }
                unset($instance);
            } else {
                $kind = substr($operation, 7);
                $observed[$kind] = array_values(array_filter(
                    $observed[$kind],
                    fn (array $item): bool => ($item['name'] ?? $item['path']) !== $identity,
                ));
            }
            if (! $crashed && $operation === $crashOperation) {
                $crashed = true;

                throw new RuntimeException('simulated crash');
            }
        },
        fn (): \DateTimeImmutable => new \DateTimeImmutable($now),
        new OperationLock($paths),
        new OperationId(str_repeat('b', 32)),
    );
}

function crashBeforeMutationRetirementService(
    array &$observed,
    array &$operations,
    string $now,
    string $crashOperation,
): LegacyRetirement {
    $crashed = false;
    $paths = new \App\E2E\State\StatePaths(temporaryPath('legacy-retirement-lock-', 6));

    return new LegacyRetirement(
        function () use (&$observed): array {
            return $observed;
        },
        function (string $operation, array $resource) use (&$operations, &$observed, $crashOperation, &$crashed): void {
            $identity = $resource['name'] ?? $resource['path'];
            $operations[] = [$operation, $identity, 'before'];
            if (! $crashed && $operation === $crashOperation) {
                $crashed = true;

                throw new RuntimeException('simulated crash');
            }
            if ($operation === 'stop') {
                foreach ($observed['instances'] as &$instance) {
                    if ($instance['name'] === $identity) {
                        $instance['status'] = 'STOPPED';
                    }
                }
                unset($instance);

                return;
            }
            $kind = substr($operation, 7);
            $observed[$kind] = array_values(array_filter(
                $observed[$kind],
                fn (array $item): bool => ($item['name'] ?? $item['path']) !== $identity,
            ));
        },
        fn (): \DateTimeImmutable => new \DateTimeImmutable($now),
        new OperationLock($paths),
        new OperationId(str_repeat('c', 32)),
    );
}

/** @mago-expect lint:kan-defect The lifecycle matrix keeps recovery and destructive-order proof in one specification. */
describe('legacy retirement', function () {
    it('protects compact topology networks and all accepted feature issue identities', function (
        string $kind,
        string $identity,
    ): void {
        expect(fn () => \App\E2E\Value\RetirementInventory::assertLegacyCandidate($kind, [
            'identity' => $identity,
            'classification' => 'legacy',
            'remote' => 'local',
            'project' => 'default',
            'dependencies' => [],
        ]))
            ->toThrow(InvalidArgumentException::class, 'protected resource');
    })->with([
        'compact topology snapshot network' => ['networks', 'oe-topo-snap'],
        'compact feature network' => ['networks', 'oe-a1b2c3d4e5f6'],
        'canonical topology snapshot VM' => ['instances', 'orbit-e2e-topology-snapshot-gateway'],
        'canonical ORBIT feature VM' => ['instances', 'orbit-e2e-orbit-123456789-aaaaaaaa-gateway'],
        'canonical ORBIT feature snapshot' => ['snapshots', 'orbit-e2e-orbit-123456789-aaaaaaaa-gateway/ready'],
        'retired topology snapshot network' => ['networks', 'oe-standby'],
        'retired topology snapshot VM' => ['instances', 'orbit-e2e-standby-gateway'],
    ]);

    it('keeps similar-looking foreign identities eligible when classified as legacy', function (
        string $kind,
        string $identity,
    ): void {
        expect(fn () => \App\E2E\Value\RetirementInventory::assertLegacyCandidate($kind, [
            'identity' => $identity,
            'classification' => 'legacy',
            'remote' => 'local',
            'project' => 'default',
            'dependencies' => [],
        ]))
            ->not
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'foreign compact network' => ['networks', 'oe-a1b2c3d4e5f'],
        'foreign VM prefix' => ['instances', 'orbit-e2e-orbitx-0-1'],
        'foreign topology snapshot spelling' => ['networks', 'oe-topo-snap-extra'],
        'removed live topology snapshot network' => ['networks', 'oe-l-topo-snap'],
        'removed live topology snapshot VM' => ['instances', 'orbit-e2e-live-topology-snapshot-gateway'],
        'removed live standby network' => ['networks', 'oe-live-standby'],
        'removed live standby VM' => ['instances', 'orbit-e2e-live-standby-gateway'],
    ]);

    it('rejects symbolic-link parents for every protected JSON input and provider observation', function () {
        $root = temporaryPath('legacy-read-', 5);
        mkdir($root.'/real', 0700, true);
        file_put_contents($root.'/real/state.json', '{}');
        chmod($root.'/real/state.json', 0600);
        symlink($root.'/real', $root.'/escape');
        $path = $root.'/escape/state.json';

        foreach (['inventory', 'quarantine', 'retirement'] as $input) {
            expect(fn () => \App\E2E\LegacyRetirement::readProtectedJson($path))
                ->toThrow(RuntimeException::class, 'symbolic-link component');
        }
        unlink($root.'/escape');
        unlink($root.'/real/state.json');
        rmdir($root.'/real');
        rmdir($root);
    });

    it('rejects correctly rehashed preserve and protected targets before mutation', function () {
        $observed = legacyFixture();
        $operations = [];
        $service = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00');
        $inventory = $service->inventory();
        $evidence = temporaryFile('freeze-');
        file_put_contents($evidence, 'frozen');
        chmod($evidence, 0600);
        $valid = $service->quarantine($inventory, $inventory->sha256(), $evidence)->toArray();

        foreach (['preserve', 'protected'] as $forgery) {
            $value = $valid;
            $target = &$value['targets'][1];
            $target['observed']['classification'] = $forgery === 'preserve' ? 'preserve' : 'legacy';
            if ($forgery === 'protected') {
                $target['observed']['name'] = 'orbit-e2e-topology-snapshot-forged';
                $target['identity'] = 'orbit-e2e-topology-snapshot-forged';
            }
            unset($target['observed']['sha256']);
            $target['observed']['sha256'] = hash('sha256', json_encode(
                $target['observed'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            $target['observed_sha256'] = hash('sha256', json_encode(
                $target['observed'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            unset($target);
            expect(fn () => \App\E2E\Value\QuarantineManifest::fromArray($value))
                ->toThrow(InvalidArgumentException::class);
        }
        expect($operations)->toHaveCount(1);
        unlink($evidence);
    });

    it('rejects every malformed nested inventory shape with InvalidArgumentException', function () {
        $observed = legacyFixture();
        $operations = [];
        $valid = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00')->inventory()->toArray();
        $malformed = [];
        $case = $valid;
        $case['candidates']['instances'][0] = 'scalar';
        $malformed[] = $case;
        $case = $valid;
        unset($case['candidates']['instances'][0]['sha256']);
        $malformed[] = $case;
        $case = $valid;
        $case['candidates']['instances'][0]['extra'] = true;
        $malformed[] = $case;
        $case = $valid;
        $case['candidates']['instances'][0]['metadata'] = 'invalid';
        $malformed[] = $case;

        foreach ($malformed as $value) {
            expect(fn () => \App\E2E\Value\RetirementInventory::fromArray($value))
                ->toThrow(InvalidArgumentException::class);
        }
    });

    it('rejects every malformed nested quarantine shape with InvalidArgumentException', function () {
        $observed = legacyFixture();
        $operations = [];
        $service = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00');
        $inventory = $service->inventory();
        $evidence = temporaryFile('freeze-');
        file_put_contents($evidence, 'frozen');
        chmod($evidence, 0600);
        $valid = $service->quarantine($inventory, $inventory->sha256(), $evidence)->toArray();
        $malformed = [];
        $case = $valid;
        $case['targets'][0] = 17;
        $malformed[] = $case;
        $case = $valid;
        unset($case['targets'][0]['result']);
        $malformed[] = $case;
        $case = $valid;
        $case['targets'][0]['extra'] = true;
        $malformed[] = $case;
        $case = $valid;
        $case['targets'][0]['recovery'] = 'invalid';
        $malformed[] = $case;
        $case = $valid;
        $case['freeze_evidence']['extra'] = true;
        $malformed[] = $case;
        $case = $valid;
        $case['freeze_evidence'] = [
            'sha256' => $case['freeze_evidence']['sha256'],
            'path' => $case['freeze_evidence']['path'],
            'mode' => $case['freeze_evidence']['mode'],
        ];
        $malformed[] = $case;
        $case = $valid;
        $case['targets'] = [3 => $case['targets'][0]];
        $malformed[] = $case;
        $case = $valid;
        [$case['targets'][1], $case['targets'][2]] = [$case['targets'][2], $case['targets'][1]];
        $malformed[] = $case;

        foreach ($malformed as $value) {
            expect(fn () => \App\E2E\Value\QuarantineManifest::fromArray($value))
                ->toThrow(InvalidArgumentException::class);
        }
        unlink($evidence);
    });

    it('rejects every malformed nested retirement result with InvalidArgumentException', function () {
        $observed = legacyFixture();
        $operations = [];
        $preserved = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00')->inventory()->preserved;
        $valid = new \App\E2E\Value\RetirementResult(
            true,
            [['kind' => 'instances', 'identity' => 'orbit-template-api', 'result' => 'deleted']],
            [],
            $preserved,
            str_repeat('a', 64),
        )->toArray();
        $malformed = [];
        $case = $valid;
        $case['deleted'][0] = false;
        $malformed[] = $case;
        $case = $valid;
        unset($case['deleted'][0]['result']);
        $malformed[] = $case;
        $case = $valid;
        $case['deleted'][0]['extra'] = true;
        $malformed[] = $case;
        $case = $valid;
        $case['deleted'][0]['identity'] = [];
        $malformed[] = $case;
        $case = $valid;
        $case['deleted'] = [4 => $case['deleted'][0]];
        $malformed[] = $case;
        $case = $valid;
        $case['remaining'] = [8 => [
            'kind' => 'instances',
            'identity' => 'orbit-template-api',
            'result' => 'remaining',
            'reason' => 'not_deleted',
        ]];
        $malformed[] = $case;

        foreach ($malformed as $value) {
            expect(fn () => \App\E2E\Value\RetirementResult::fromArray($value))
                ->toThrow(InvalidArgumentException::class);
        }
    });

    it('requires reviewed classification and never infers retirement from an orbit name', function () {
        $observed = ['instances' => [['name' => 'orbit-looks-old', 'status' => 'STOPPED']]];
        $operations = [];

        expect(fn () => retirementService($observed, $operations, '2026-08-28T10:00:00+00:00')->inventory())
            ->toThrow(RuntimeException::class, 'reviewed classification');
    });

    it('inventories exact candidates and preserves protected identities', function () {
        $observed = legacyFixture();
        $operations = [];
        $inventory = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00')->inventory();

        expect(array_column($inventory->candidates['instances'], 'name'))
            ->toBe(['orbit-e2e-dev-42', 'orbit-template-api'])
            ->and(array_column($inventory->preserved['instances'], 'name'))
            ->toBe(['database', 'orbit-e2e-topology-snapshot-gateway'])
            ->and($inventory->preserved['pools'][0]['identity'])
            ->toBe('pool-uuid-1')
            ->and($operations)
            ->toBe([]);
    });

    it('requires exact review and freeze evidence and records reversible quarantine', function () {
        $observed = legacyFixture();
        $operations = [];
        $service = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00');
        $inventory = $service->inventory();
        $evidence = temporaryFile('freeze-');
        file_put_contents($evidence, 'old acquisition disabled');
        chmod($evidence, 0600);

        $manifest = $service->quarantine($inventory, $inventory->sha256(), $evidence);

        expect($operations)
            ->toContain(['stop', 'orbit-e2e-dev-42'])
            ->and($manifest->deleteAfter)
            ->toBe('2026-09-04T10:00:00+00:00')
            ->and($manifest->targets[1])
            ->toHaveKeys(['original_status', 'metadata', 'dependencies', 'recovery']);
        unlink($evidence);
    });

    it('refuses drift and early deletion', function () {
        $observed = legacyFixture();
        $operations = [];
        $service = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00');
        $inventory = $service->inventory();
        $evidence = temporaryFile('freeze-');
        file_put_contents($evidence, 'frozen');
        chmod($evidence, 0600);
        $manifest = $service->quarantine($inventory, $inventory->sha256(), $evidence);

        expect(fn () => $service->delete($manifest, $manifest->sha256()))
            ->toThrow(RuntimeException::class, 'retention period');
        unlink($evidence);
    });

    it('stores exact filesystem types and rejects a reviewed file replaced by a directory', function () {
        $root = temporaryPath('legacy-type-', 5);
        mkdir($root.'/manifests', 0700, true);
        $path = $root.'/manifests/old.json';
        file_put_contents($path, '{}');
        $observed = [
            'manifests' => [[
                'path' => $path,
                'safe_root' => $root.'/manifests',
                'filesystem_type' => 'file',
                'classification' => 'legacy',
            ]],
        ];
        $operations = [];
        $service = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00');
        $inventory = $service->inventory();
        $evidence = temporaryFile('freeze-');
        file_put_contents($evidence, 'frozen');
        chmod($evidence, 0600);
        $manifest = $service->quarantine($inventory, $inventory->sha256(), $evidence);
        unlink($path);
        mkdir($path, 0700);

        expect($inventory->candidates['manifests'][0]['filesystem_type'])
            ->toBe('file')
            ->and($manifest->targets[0]['observed']['filesystem_type'])
            ->toBe('file')
            ->and($manifest->freezeEvidence['filesystem_type'])
            ->toBe('file')
            ->and(fn () => retirementService($observed, $operations, '2026-09-05T10:00:00+00:00')->delete(
                $manifest,
                $manifest->sha256(),
            ))
            ->toThrow(RuntimeException::class, 'filesystem type')
            ->and($operations)
            ->toBeEmpty();

        rmdir($path);
        rmdir($root.'/manifests');
        rmdir($root);
        unlink($evidence);
    });

    it('binds quarantine to unchanged external freeze evidence', function () {
        $observed = legacyFixture();
        $operations = [];
        $first = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00');
        $inventory = $first->inventory();
        $evidence = temporaryFile('freeze-');
        file_put_contents($evidence, 'frozen');
        chmod($evidence, 0600);
        $manifest = $first->quarantine($inventory, $inventory->sha256(), $evidence);
        file_put_contents($evidence, 'changed');

        expect(fn () => retirementService($observed, $operations, '2026-09-05T10:00:00+00:00')->delete(
            $manifest,
            $manifest->sha256(),
        ))
            ->toThrow(RuntimeException::class, 'freeze evidence changed');
        unlink($evidence);
    });

    it('writes prepared quarantine recovery state before stopping a VM', function () {
        $observed = legacyFixture();
        $operations = [];
        $service = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00');
        $inventory = $service->inventory();
        $evidence = temporaryFile('freeze-');
        file_put_contents($evidence, 'frozen');
        chmod($evidence, 0600);

        expect(fn () => $service->quarantine(
            $inventory,
            $inventory->sha256(),
            $evidence,
            '/missing-parent/recovery.json',
        ))
            ->toThrow(RuntimeException::class, 'output directory')
            ->and($operations)
            ->toBeEmpty();
        unlink($evidence);
    });

    it('rejects fresh quarantine when the reviewed inventory drifted before journaling', function () {
        $observed = legacyFixture();
        $operations = [];
        $service = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00');
        $inventory = $service->inventory();
        $observed['instances'][1]['status'] = 'STOPPED';
        $journal = temporaryFile('legacy-journal-');
        chmod($journal, 0600);
        unlink($journal);
        $evidence = temporaryFile('freeze-');
        file_put_contents($evidence, 'frozen');
        chmod($evidence, 0600);

        expect(fn () => $service->quarantine($inventory, $inventory->sha256(), $evidence, $journal))
            ->toThrow(RuntimeException::class, 'drifted')
            ->and($operations)
            ->toBeEmpty()
            ->and(is_file($journal))
            ->toBeFalse();

        unlink($evidence);
    });

    it('resumes quarantine after a crash before the stop and keeps the journal manifest timestamps', function () {
        $observed = legacyFixture();
        $operations = [];
        $journal = temporaryFile('legacy-journal-');
        chmod($journal, 0600);
        unlink($journal);
        $service = crashBeforeMutationRetirementService($observed, $operations, '2026-08-28T10:00:00+00:00', 'stop');
        $inventory = $service->inventory();
        $evidence = temporaryFile('freeze-');
        file_put_contents($evidence, 'frozen');
        chmod($evidence, 0600);

        expect(fn () => $service->quarantine($inventory, $inventory->sha256(), $evidence, $journal))
            ->toThrow(RuntimeException::class, 'simulated crash');

        $pendingJournal = LegacyRetirement::readProtectedJson($journal);

        expect($pendingJournal['phase'])
            ->toBe('pending')
            ->and($observed['instances'][1]['status'])
            ->toBe('RUNNING');

        $resumed = retirementService($observed, $operations, '2026-09-02T10:00:00+00:00')
            ->quarantine($inventory, $inventory->sha256(), $evidence, $journal);

        expect(array_values(array_filter(
            $operations,
            fn (array $entry): bool => $entry[0] === 'stop',
        )))
            ->toHaveCount(2)
            ->and($resumed->quarantinedAt)
            ->toBe($pendingJournal['manifest']['quarantined_at'])
            ->and($resumed->deleteAfter)
            ->toBe($pendingJournal['manifest']['delete_after']);

        unlink($journal);
        unlink($evidence);
    });

    it('resumes quarantine after a crash without repeating the stop', function () {
        $observed = legacyFixture();
        $operations = [];
        $journal = temporaryFile('legacy-journal-');
        chmod($journal, 0600);
        unlink($journal);
        $service = crashingRetirementService($observed, $operations, '2026-08-28T10:00:00+00:00', 'stop');
        $inventory = $service->inventory();
        $evidence = temporaryFile('freeze-');
        file_put_contents($evidence, 'frozen');
        chmod($evidence, 0600);

        expect(fn () => $service->quarantine($inventory, $inventory->sha256(), $evidence, $journal))
            ->toThrow(RuntimeException::class, 'simulated crash');

        expect($operations)
            ->toBe([['stop', 'orbit-e2e-dev-42']])
            ->and(LegacyRetirement::readProtectedJson($journal)['phase'])
            ->toBe('pending');

        $resumed = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00')
            ->quarantine($inventory, $inventory->sha256(), $evidence, $journal);

        expect($operations)
            ->toBe([['stop', 'orbit-e2e-dev-42']])
            ->and($resumed->targets[1]['identity'])
            ->toBe('orbit-e2e-dev-42');

        unlink($journal);
        unlink($evidence);
    });

    it('resumes delete after a crash without repeating the deletion', function () {
        $observed = legacyFixture();
        $operations = [];
        $evidence = temporaryFile('freeze-');
        file_put_contents($evidence, 'frozen');
        chmod($evidence, 0600);
        $prepared = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00');
        $inventory = $prepared->inventory();
        $manifest = $prepared->quarantine($inventory, $inventory->sha256(), $evidence);
        $journal = temporaryFile('legacy-journal-');
        chmod($journal, 0600);
        unlink($journal);

        expect(fn () => crashingRetirementService(
            $observed,
            $operations,
            '2026-09-05T10:00:00+00:00',
            'delete_snapshots',
        )->delete($manifest, $manifest->sha256(), $journal))
            ->toThrow(RuntimeException::class, 'simulated crash');

        expect(LegacyRetirement::readProtectedJson($journal)['phase'])
            ->toBe('pending');

        $resumed = retirementService($observed, $operations, '2026-09-05T10:00:00+00:00')
            ->delete($manifest, $manifest->sha256(), $journal);

        expect(array_values(array_filter(
            $operations,
            fn (array $entry): bool => $entry === ['delete_snapshots', 'orbit-template-api/ready'],
        )))
            ->toHaveCount(1)
            ->and($resumed->successful)
            ->toBeTrue();

        unlink($journal);
        unlink($evidence);
    });

    it('resumes delete after a crash before the deletion and performs the mutation once on retry', function () {
        $observed = legacyFixture();
        $operations = [];
        $evidence = temporaryFile('freeze-');
        file_put_contents($evidence, 'frozen');
        chmod($evidence, 0600);
        $prepared = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00');
        $inventory = $prepared->inventory();
        $manifest = $prepared->quarantine($inventory, $inventory->sha256(), $evidence);
        $journal = temporaryFile('legacy-journal-');
        chmod($journal, 0600);
        unlink($journal);

        expect(fn () => crashBeforeMutationRetirementService(
            $observed,
            $operations,
            '2026-09-05T10:00:00+00:00',
            'delete_snapshots',
        )->delete($manifest, $manifest->sha256(), $journal))
            ->toThrow(RuntimeException::class, 'simulated crash');

        expect(LegacyRetirement::readProtectedJson($journal)['phase'])
            ->toBe('pending')
            ->and($observed['snapshots'])
            ->toHaveCount(1);

        $resumed = retirementService($observed, $operations, '2026-09-05T10:00:00+00:00')
            ->delete($manifest, $manifest->sha256(), $journal);

        expect($observed['snapshots'])
            ->toBe([])
            ->and($resumed->successful)
            ->toBeTrue();

        unlink($journal);
        unlink($evidence);
    });

    it('rejects recovery journals with foreign completed or pending identities', function () {
        $observed = legacyFixture();
        $operations = [];
        $service = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00');
        $inventory = $service->inventory();
        $evidence = temporaryFile('freeze-');
        file_put_contents($evidence, 'frozen');
        chmod($evidence, 0600);
        $manifest = $service->quarantine($inventory, $inventory->sha256(), $evidence);
        $journal = temporaryFile('legacy-journal-');
        chmod($journal, 0600);

        $service->write($journal, [
            'operation' => 'delete',
            'phase' => 'pending',
            'quarantine_sha256' => $manifest->sha256(),
            'freeze_evidence' => $manifest->freezeEvidence,
            'manifest' => $manifest->toArray(),
            'targets' => $manifest->targets,
            'pending' => null,
            'completed' => [['kind' => 'instances', 'identity' => 'foreign-instance']],
        ]);

        expect(fn () => retirementService($observed, $operations, '2026-09-05T10:00:00+00:00')
            ->delete($manifest, $manifest->sha256(), $journal))
            ->toThrow(RuntimeException::class);

        $service->write($journal, [
            'version' => 1,
            'operation' => 'quarantine',
            'phase' => 'pending',
            'inventory_sha256' => $inventory->sha256(),
            'freeze_evidence' => $manifest->freezeEvidence,
            'manifest' => $manifest->toArray(),
            'targets' => $manifest->targets,
            'completed' => [['kind' => 'instances', 'identity' => 'orbit-e2e-dev-42']],
            'pending' => [
                'kind' => 'instances',
                'identity' => 'foreign-instance',
            ],
        ]);

        expect(fn () => retirementService($observed, $operations, '2026-08-28T10:00:00+00:00')
            ->quarantine($inventory, $inventory->sha256(), $evidence, $journal))
            ->toThrow(RuntimeException::class);

        unlink($journal);
        unlink($evidence);
    });

    it('rejects recovery journals with a corrupt phase', function () {
        $observed = legacyFixture();
        $operations = [];
        $service = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00');
        $inventory = $service->inventory();
        $evidence = temporaryFile('freeze-');
        file_put_contents($evidence, 'frozen');
        chmod($evidence, 0600);
        $manifest = $service->quarantine($inventory, $inventory->sha256(), $evidence);
        $journal = temporaryFile('legacy-journal-');
        chmod($journal, 0600);

        $service->write($journal, [
            'version' => 1,
            'operation' => 'delete',
            'phase' => 'broken',
            'quarantine_sha256' => $manifest->sha256(),
            'freeze_evidence' => $manifest->freezeEvidence,
            'manifest' => $manifest->toArray(),
            'targets' => $manifest->targets,
            'pending' => null,
            'completed' => [],
        ]);

        expect(fn () => retirementService($observed, $operations, '2026-09-05T10:00:00+00:00')
            ->delete($manifest, $manifest->sha256(), $journal))
            ->toThrow(RuntimeException::class);

        unlink($journal);
        unlink($evidence);
    });

    it('rejects duplicate manifest identities and non-existing safe targets', function () {
        $observed = legacyFixture();
        $operations = [];
        $inventory = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00')->inventory();
        $groups = $inventory->candidates;
        $groups['instances'][] = $groups['instances'][0];
        expect(fn () => new \App\E2E\Value\RetirementInventory($groups, $inventory->preserved, $inventory->createdAt))
            ->toThrow(InvalidArgumentException::class, 'unique');

        $pathRoot = temporaryPath('missing-legacy-', 4);
        mkdir($pathRoot, 0700);
        $source = ['path' => $pathRoot.'/gone', 'safe_root' => $pathRoot, 'classification' => 'legacy'];
        $smallObserved = [
            'source_paths' => [$source],
            'pools' => [['name' => 'orbit-e2e', 'classification' => 'preserve']],
        ];
        $smallOperations = [];
        mkdir($source['path'], 0700);
        $early = retirementService($smallObserved, $smallOperations, '2026-08-28T10:00:00+00:00');
        $smallInventory = $early->inventory();
        $evidence = temporaryFile('freeze-');
        file_put_contents($evidence, 'frozen');
        chmod($evidence, 0600);
        $manifest = $early->quarantine($smallInventory, $smallInventory->sha256(), $evidence);
        rmdir($source['path']);
        expect(fn () => retirementService($smallObserved, $smallOperations, '2026-09-05T10:00:00+00:00')->delete(
            $manifest,
            $manifest->sha256(),
        ))
            ->toThrow(RuntimeException::class, 'outside its reviewed safe root')
            ->and($smallOperations)
            ->toBeEmpty();
        unlink($evidence);
        rmdir($pathRoot);
    });

    it('deletes in exact dependency order and verifies absence and preservation', function () {
        $observed = legacyFixture();
        $operations = [];
        $first = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00');
        $inventory = $first->inventory();
        $evidence = temporaryFile('freeze-');
        file_put_contents($evidence, 'frozen');
        chmod($evidence, 0600);
        $manifest = $first->quarantine($inventory, $inventory->sha256(), $evidence);
        $later = retirementService($observed, $operations, '2026-09-05T10:00:00+00:00');
        $result = $later->delete($manifest, $manifest->sha256());
        $verified = $later->verify($result);
        $deletedSource = array_values(array_filter(
            $result->deleted,
            fn (array $resource): bool => $resource['kind'] === 'source_paths',
        ));

        expect(array_column($operations, 0))
            ->toBe([
                'stop',
                'delete_snapshots',
                'delete_instances',
                'delete_instances',
                'delete_networks',
                'delete_source_paths',
                'delete_manifests',
                'delete_locks',
            ])
            ->and($verified->successful)
            ->toBeTrue()
            ->and($deletedSource)
            ->toHaveCount(1)
            ->and($deletedSource[0]['filesystem_type'])
            ->toBe('directory')
            ->and($observed['pools'][0]['identity'])
            ->toBe('pool-uuid-1');
        unlink($evidence);
    });

    it('revalidates a later target at the process barrier after an earlier deletion', function () {
        $observed = legacyFixture();
        $operations = [];
        $evidence = temporaryFile('freeze-');
        file_put_contents($evidence, 'frozen');
        chmod($evidence, 0600);
        $prepared = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00');
        $inventory = $prepared->inventory();
        $manifest = $prepared->quarantine($inventory, $inventory->sha256(), $evidence);
        $observations = 0;
        $paths = new \App\E2E\State\StatePaths(temporaryPath('legacy-barrier-', 5));
        $service = new LegacyRetirement(
            fn (): array => $observed,
            function (string $operation, array $resource) use (&$observed, &$operations): void {
                $identity = $resource['name'] ?? $resource['path'];
                $operations[] = [$operation, $identity];
                $kind = substr($operation, 7);
                $observed[$kind] = array_values(array_filter(
                    $observed[$kind],
                    fn (array $item): bool => ($item['name'] ?? $item['path']) !== $identity,
                ));
                if ($operation === 'delete_snapshots') {
                    foreach ($observed['instances'] as &$instance) {
                        if ($instance['name'] === 'orbit-e2e-dev-42') {
                            $instance['metadata']['owner'] = 'replacement';
                        }
                    }
                    unset($instance);
                }
            },
            fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-09-05T10:00:00+00:00'),
            new OperationLock($paths),
            new OperationId(str_repeat('d', 32)),
            function () use (&$observed, &$observations): array {
                $observations++;

                return $observed;
            },
        );

        expect(fn () => $service->delete($manifest, $manifest->sha256()))
            ->toThrow(RuntimeException::class, 'drifted before deletion')
            ->and($operations)
            ->toContain(['delete_snapshots', 'orbit-template-api/ready'])
            ->not
            ->toContain(['delete_instances', 'orbit-e2e-dev-42'])
            ->and($observations)
            ->toBe(2);

        unlink($evidence);
    });

    it('reports only exact remaining resources after partial deletion', function () {
        $observed = legacyFixture();
        $operations = [];
        $service = retirementService($observed, $operations, '2026-09-05T10:00:00+00:00');
        $result = new \App\E2E\Value\RetirementResult(
            true,
            [['kind' => 'instances', 'identity' => 'orbit-template-api', 'result' => 'deleted']],
            [],
            $service->inventory()->preserved,
            str_repeat('a', 64),
        );

        expect($service->verify($result)->remaining)
            ->toContain([
                'kind' => 'instances',
                'identity' => 'orbit-template-api',
                'result' => 'remaining',
                'reason' => 'not_deleted',
            ])
            ->toContain([
                'kind' => 'networks',
                'identity' => 'legacy-net',
                'result' => 'remaining',
                'reason' => 'unexpected_legacy_identity',
            ]);
    });
});
