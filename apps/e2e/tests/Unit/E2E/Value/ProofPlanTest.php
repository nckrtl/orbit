<?php

declare(strict_types=1);

use App\E2E\Value\ProofPlan;

/** @return array{setup:list<array<string, mixed>>,acceptance:list<array<string, mixed>>} */
function proofPlanFixture(): array
{
    return [
        'setup' => [
            [
                'id' => 'create-workspace',
                'node' => 'app-dev',
                'argv' => ['orbit', 'workspace:create', 'example'],
                'timeout_seconds' => 120,
            ],
        ],
        'acceptance' => [
            [
                'id' => 'show-workspace',
                'node' => 'app-dev',
                'argv' => ['orbit', 'workspace:show', 'example', '--json'],
                'timeout_seconds' => 60,
            ],
        ],
    ];
}

/** @param array<string, mixed>|string $plan */
function proofPlanFile(array|string $plan): string
{
    $path = temporaryFile('orbit-proof-plan-');
    file_put_contents($path, is_string($plan) ? $plan : json_encode($plan, JSON_THROW_ON_ERROR));

    return $path;
}

/** @param Closure(array<string, mixed>): array<string, mixed> $mutate */
function mutatedProofPlanFile(Closure $mutate): string
{
    return proofPlanFile($mutate(proofPlanFixture()));
}

describe('ProofPlan', function (): void {
    it('reads the declared setup, acceptance, and post-deployment actions from a file', function (): void {
        $plan = ProofPlan::fromFile(proofPlanFile(proofPlanFixture()));

        expect($plan->setup)
            ->toBe([
                [
                    'id' => 'create-workspace',
                    'node' => 'app-dev',
                    'argv' => ['orbit', 'workspace:create', 'example'],
                    'timeout_seconds' => 120,
                ],
            ])
            ->and($plan->acceptance)
            ->toBe([
                [
                    'id' => 'show-workspace',
                    'node' => 'app-dev',
                    'argv' => ['orbit', 'workspace:show', 'example', '--json'],
                    'timeout_seconds' => 60,
                ],
            ])
            ->and($plan->toArray())
            ->toBe(proofPlanFixture());
    });

    it('serializes in canonical key order regardless of the declared order', function (): void {
        $plan = ProofPlan::fromFile(proofPlanFile([
            'acceptance' => [
                ['timeout_seconds' => 60, 'argv' => ['orbit', 'doctor'], 'node' => 'gateway', 'id' => 'doctor'],
            ],
            'setup' => [],
        ]));

        expect($plan->toArray())
            ->toBe([
                'setup' => [],
                'acceptance' => [
                    ['id' => 'doctor', 'node' => 'gateway', 'argv' => ['orbit', 'doctor'], 'timeout_seconds' => 60],
                ],
            ]);
    });

    it('rejects a missing or unreadable file', function (): void {
        expect(fn () => ProofPlan::fromFile(temporaryPath('orbit-proof-plan-missing-')))
            ->toThrow(InvalidArgumentException::class, 'The proof plan file cannot be read.');
    });

    it('rejects a file that is not a JSON object', function (string $content): void {
        expect(fn () => ProofPlan::fromFile(proofPlanFile($content)))
            ->toThrow(InvalidArgumentException::class, 'The proof plan must be a JSON object.');
    })->with([
        'invalid JSON' => ['{"setup": ['],
        'list' => ['[]'],
        'scalar' => ['"plan"'],
        'empty' => [''],
    ]);

    it('rejects anything but the exact top-level keys', function (Closure $mutate): void {
        expect(fn () => ProofPlan::fromFile(mutatedProofPlanFile($mutate)))
            ->toThrow(
                InvalidArgumentException::class,
                'The proof plan must have exactly the keys setup and acceptance.',
            );
    })->with([
        'missing setup' => [function (array $plan): array {
            unset($plan['setup']);

            return $plan;
        }],
        'post-deployment actions' => [fn (array $plan): array => $plan + ['post_deployment_actions' => []]],
        'schema version' => [fn (array $plan): array => $plan + ['schema' => 1]],
        'stdin at the top level' => [fn (array $plan): array => $plan + ['stdin' => 'token']],
    ]);

    it('rejects a section that is not a list', function (string $section, mixed $value): void {
        expect(fn () => ProofPlan::fromFile(mutatedProofPlanFile(function (array $plan) use ($section, $value): array {
            $plan[$section] = $value;

            return $plan;
        })))
            ->toThrow(InvalidArgumentException::class, "The proof plan section [{$section}] must be a list.");
    })->with([
        'setup object' => ['setup', ['id' => 'x']],
        'acceptance string' => ['acceptance', 'show-workspace'],
    ]);

    it('rejects a section declared as a JSON object even when its keys look like list indexes', function (): void {
        $content = json_encode([
            'setup' => [],
            'acceptance' => ['0' => proofPlanFixture()['acceptance'][0]],
        ], JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
        $content = str_replace('"setup":{}', '"setup":[]', $content);

        expect($content)
            ->toContain('"acceptance":{"0":')
            ->and(fn () => ProofPlan::fromFile(proofPlanFile($content)))
            ->toThrow(InvalidArgumentException::class, 'The proof plan section [acceptance] must be a list.');
    });

    it('requires at least one acceptance action', function (): void {
        expect(fn () => ProofPlan::fromFile(mutatedProofPlanFile(function (array $plan): array {
            $plan['acceptance'] = [];

            return $plan;
        })))
            ->toThrow(InvalidArgumentException::class, 'The proof plan must declare at least one acceptance action.');
    });

    it('rejects an action carrying stdin before any other rule', function (): void {
        expect(fn () => ProofPlan::fromFile(mutatedProofPlanFile(function (array $plan): array {
            $plan['setup'][0]['stdin'] = 'Bearer secret';

            return $plan;
        })))
            ->toThrow(
                InvalidArgumentException::class,
                'Proof action [setup#0] cannot carry stdin; the plan must not hold secrets.',
            );
    });

    it('rejects anything but the exact action keys', function (Closure $mutate): void {
        expect(fn () => ProofPlan::fromFile(mutatedProofPlanFile($mutate)))
            ->toThrow(
                InvalidArgumentException::class,
                'Proof action [acceptance#0] must have exactly the keys id, node, argv, and timeout_seconds.',
            );
    })->with([
        'missing timeout' => [function (array $plan): array {
            unset($plan['acceptance'][0]['timeout_seconds']);

            return $plan;
        }],
        'extra key' => [function (array $plan): array {
            $plan['acceptance'][0]['env'] = ['ORBIT_HOME' => '/home/orbit/.orbit'];

            return $plan;
        }],
        'not an object' => [function (array $plan): array {
            $plan['acceptance'][0] = 'show-workspace';

            return $plan;
        }],
    ]);

    it('rejects an action ID that is not lowercase letters, digits, and hyphens', function (mixed $id): void {
        expect(fn () => ProofPlan::fromFile(mutatedProofPlanFile(function (array $plan) use ($id): array {
            $plan['setup'][0]['id'] = $id;

            return $plan;
        })))
            ->toThrow(
                InvalidArgumentException::class,
                'Proof action [setup#0] must have an ID of 1 through 64 lowercase letters, digits, and hyphens.',
            );
    })->with([
        'uppercase' => ['Create-Workspace'],
        'empty' => [''],
        'space' => ['create workspace'],
        'newline' => ["create\nworkspace"],
        'NUL byte' => ["create\0workspace"],
        'leading hyphen' => ['-create'],
        'too long' => [str_repeat('a', 65)],
        'integer' => [7],
    ]);

    it('accepts a 64 character action ID', function (): void {
        $plan = ProofPlan::fromFile(mutatedProofPlanFile(function (array $plan): array {
            $plan['setup'][0]['id'] = str_repeat('a', 64);

            return $plan;
        }));

        expect($plan->setup[0]['id'])->toBe(str_repeat('a', 64));
    });

    it('rejects a duplicate action ID across sections', function (): void {
        expect(fn () => ProofPlan::fromFile(mutatedProofPlanFile(function (array $plan): array {
            $plan['acceptance'][0]['id'] = 'create-workspace';

            return $plan;
        })))
            ->toThrow(
                InvalidArgumentException::class,
                'Proof action ID [create-workspace] is declared more than once.',
            );
    });

    it('rejects a node outside the topology profile', function (mixed $node): void {
        expect(fn () => ProofPlan::fromFile(mutatedProofPlanFile(function (array $plan) use ($node): array {
            $plan['setup'][0]['node'] = $node;

            return $plan;
        })))
            ->toThrow(
                InvalidArgumentException::class,
                'Proof action [create-workspace] must name a node from gateway, app-dev, app-prod.',
            );
    })->with([
        'unknown role' => ['db'],
        'uppercase role' => ['GATEWAY'],
        'empty' => [''],
        'integer' => [1],
    ]);

    it('rejects an empty or non-list argument vector', function (mixed $argv): void {
        expect(fn () => ProofPlan::fromFile(mutatedProofPlanFile(function (array $plan) use ($argv): array {
            $plan['setup'][0]['argv'] = $argv;

            return $plan;
        })))
            ->toThrow(
                InvalidArgumentException::class,
                'Proof action [create-workspace] must have a non-empty argument vector.',
            );
    })->with([
        'empty' => [[]],
        'string' => ['orbit workspace:create'],
        'object' => [['command' => 'orbit']],
    ]);

    it('rejects a first argument that env would consume as its own', function (string $program): void {
        expect(fn () => ProofPlan::fromFile(mutatedProofPlanFile(function (array $plan) use ($program): array {
            $plan['setup'][0]['argv'] = [$program, 'workspace:create'];

            return $plan;
        })))
            ->toThrow(InvalidArgumentException::class, 'Proof action [create-workspace] must start with a program');
    })->with([
        'assignment' => ['DB_DATABASE=/tmp/other.sqlite'],
        'option' => ['--unset=HOME'],
        'empty' => [''],
    ]);

    it('rejects an argument that is not a string or carries a NUL byte or newline', function (mixed $argument): void {
        expect(fn () => ProofPlan::fromFile(mutatedProofPlanFile(function (array $plan) use ($argument): array {
            $plan['setup'][0]['argv'] = ['orbit', $argument];

            return $plan;
        })))
            ->toThrow(
                InvalidArgumentException::class,
                'Proof action [create-workspace] has an argument that is not one string free of NUL bytes and newlines.',
            );
    })->with([
        'NUL byte' => ["work\0space"],
        'newline' => ["work\nspace"],
        'carriage return' => ["work\rspace"],
        'integer' => [1],
        'null' => [null],
    ]);

    it('rejects a timeout outside 1 through 900 seconds', function (mixed $timeout): void {
        expect(fn () => ProofPlan::fromFile(mutatedProofPlanFile(function (array $plan) use ($timeout): array {
            $plan['setup'][0]['timeout_seconds'] = $timeout;

            return $plan;
        })))
            ->toThrow(
                InvalidArgumentException::class,
                'Proof action [create-workspace] must have a timeout from 1 through 900 seconds.',
            );
    })->with([
        'zero' => [0],
        'negative' => [-1],
        'too long' => [901],
        'float' => [60.5],
        'numeric string' => ['60'],
        'null' => [null],
    ]);

    it('accepts the timeout bounds', function (): void {
        $plan = ProofPlan::fromFile(mutatedProofPlanFile(function (array $plan): array {
            $plan['setup'][0]['timeout_seconds'] = 1;
            $plan['acceptance'][0]['timeout_seconds'] = 900;

            return $plan;
        }));

        expect($plan->setup[0]['timeout_seconds'])
            ->toBe(1)
            ->and($plan->acceptance[0]['timeout_seconds'])
            ->toBe(900);
    });
});
