<?php

declare(strict_types=1);

use App\E2E\LegacyRetirement;

function legacyFixture(): array
{
    $root = sys_get_temp_dir().'/legacy-retirement-'.bin2hex(random_bytes(6));
    mkdir($root.'/sources', 0700, true);
    mkdir($root.'/manifests', 0700, true);
    mkdir($root.'/locks', 0700, true);
    mkdir($root.'/sources/old', 0700);
    file_put_contents($root.'/manifests/old.json', '{}');
    file_put_contents($root.'/locks/old.lock', 'locked');

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
                'name' => 'orbit-e2e-standby-gateway',
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
        'new_namespace' => [['name' => 'orbit-e2e-nck-123-gateway', 'classification' => 'preserve']],
        'evidence' => [['path' => '/evidence/proof.json', 'identity' => 'proof-1', 'classification' => 'preserve']],
    ];
}

function retirementService(array &$observed, array &$operations, string $now): LegacyRetirement
{
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
    );
}

describe('legacy retirement', function () {
    it('rejects symbolic-link parents for every protected JSON input and provider observation', function () {
        $root = sys_get_temp_dir().'/legacy-read-'.bin2hex(random_bytes(5));
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
        $evidence = tempnam(sys_get_temp_dir(), 'freeze-');
        file_put_contents($evidence, 'frozen');
        chmod($evidence, 0600);
        $valid = $service->quarantine($inventory, $inventory->sha256(), $evidence)->toArray();

        foreach (['preserve', 'protected'] as $forgery) {
            $value = $valid;
            $target = &$value['targets'][1];
            $target['observed']['classification'] = $forgery === 'preserve' ? 'preserve' : 'legacy';
            if ($forgery === 'protected') {
                $target['observed']['name'] = 'orbit-e2e-standby-forged';
                $target['identity'] = 'orbit-e2e-standby-forged';
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
        $evidence = tempnam(sys_get_temp_dir(), 'freeze-');
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
            ->toBe(['database', 'orbit-e2e-standby-gateway'])
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
        $evidence = tempnam(sys_get_temp_dir(), 'freeze-');
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
        $evidence = tempnam(sys_get_temp_dir(), 'freeze-');
        file_put_contents($evidence, 'frozen');
        chmod($evidence, 0600);
        $manifest = $service->quarantine($inventory, $inventory->sha256(), $evidence);

        expect(fn () => $service->delete($manifest, $manifest->sha256()))
            ->toThrow(RuntimeException::class, 'retention period');
        unlink($evidence);
    });

    it('binds quarantine to unchanged external freeze evidence', function () {
        $observed = legacyFixture();
        $operations = [];
        $first = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00');
        $inventory = $first->inventory();
        $evidence = tempnam(sys_get_temp_dir(), 'freeze-');
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
        $evidence = tempnam(sys_get_temp_dir(), 'freeze-');
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

    it('rejects duplicate manifest identities and non-existing safe targets', function () {
        $observed = legacyFixture();
        $operations = [];
        $inventory = retirementService($observed, $operations, '2026-08-28T10:00:00+00:00')->inventory();
        $groups = $inventory->candidates;
        $groups['instances'][] = $groups['instances'][0];
        expect(fn () => new \App\E2E\Value\RetirementInventory($groups, $inventory->preserved, $inventory->createdAt))
            ->toThrow(InvalidArgumentException::class, 'unique');

        $pathRoot = sys_get_temp_dir().'/missing-legacy-'.bin2hex(random_bytes(4));
        mkdir($pathRoot, 0700);
        $source = ['path' => $pathRoot.'/gone', 'safe_root' => $pathRoot, 'classification' => 'legacy'];
        $smallObserved = [
            'source_paths' => [$source],
            'pools' => [['name' => 'orbit-e2e', 'classification' => 'preserve']],
        ];
        $smallOperations = [];
        $early = retirementService($smallObserved, $smallOperations, '2026-08-28T10:00:00+00:00');
        $smallInventory = $early->inventory();
        mkdir($source['path'], 0700);
        $evidence = tempnam(sys_get_temp_dir(), 'freeze-');
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
        $evidence = tempnam(sys_get_temp_dir(), 'freeze-');
        file_put_contents($evidence, 'frozen');
        chmod($evidence, 0600);
        $manifest = $first->quarantine($inventory, $inventory->sha256(), $evidence);
        $later = retirementService($observed, $operations, '2026-09-05T10:00:00+00:00');
        $result = $later->delete($manifest, $manifest->sha256());
        $verified = $later->verify($result);

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
            ->and($observed['pools'][0]['identity'])
            ->toBe('pool-uuid-1');
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
