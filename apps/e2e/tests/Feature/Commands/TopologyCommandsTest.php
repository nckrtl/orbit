<?php

declare(strict_types=1);

use App\Console\Commands\Topology\AcquireCommand;
use App\Console\Commands\Topology\ExecCommand;
use App\Console\Commands\Topology\ProveCommand;
use App\Console\Commands\Topology\ReapCommand;
use App\Console\Commands\Topology\ReleaseCommand;
use App\Console\Commands\Topology\SyncCommand;
use App\Console\Commands\Topology\VerifyCommand;
use App\E2E\Value\OperationId;

describe('topology commands', function () {
    it('registers the complete thin topology command family', function () {
        expect([
            new AcquireCommand()->getName(),
            new SyncCommand()->getName(),
            new VerifyCommand()->getName(),
            new ExecCommand()->getName(),
            new ProveCommand()->getName(),
            new ReleaseCommand()->getName(),
            new ReapCommand()->getName(),
        ])->toBe([
            'topology:acquire',
            'topology:sync',
            'topology:verify',
            'topology:exec',
            'topology:prove',
            'topology:release',
            'topology:reap',
        ]);
    });

    it('rejects unsafe command inputs before infrastructure access', function () {
        $this
            ->artisan('topology:exec', ['issue' => 'NCK-12', 'role' => 'gateway'])
            ->expectsOutputToContain('argv JSON file')
            ->assertFailed();
        $this
            ->artisan('topology:prove', ['issue' => 'NCK-12', 'worktree' => dirname(__DIR__, 3)])
            ->expectsOutputToContain('candidate SHA')
            ->assertFailed();
        $this
            ->artisan('topology:reap')
            ->expectsOutputToContain('issue state snapshot')
            ->assertFailed();
    });

    it('returns a structured JSON failure envelope', function () {
        config(['e2e.incus.operation_id' => '0123456789abcdef0123456789abcdef']);
        app()->forgetInstance(OperationId::class);
        $this
            ->artisan('topology:exec', ['issue' => 'NCK-12', 'role' => 'gateway', '--json' => true])
            ->expectsOutput(json_encode([
                'state' => 'failed',
                'operation_id' => '0123456789abcdef0123456789abcdef',
                'error' => 'An exact argv JSON file is required.',
            ], JSON_THROW_ON_ERROR))
            ->assertFailed();
    });

    it('binds one command operation identity from the environment', function () {
        config(['e2e.incus.operation_id' => '0123456789abcdef0123456789abcdef']);
        app()->forgetInstance(OperationId::class);
        $first = app(OperationId::class);
        $second = app(OperationId::class);

        expect($first->value)->toBe('0123456789abcdef0123456789abcdef')->and($second)->toBe($first);
    });

    it('generates one 32-character hex operation identity when absent', function () {
        config(['e2e.incus.operation_id' => null]);
        app()->forgetInstance(OperationId::class);
        $id = app(OperationId::class);

        expect($id->value)->toMatch('/\A[0-9a-f]{32}\z/');
    });
});
