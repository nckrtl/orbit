<?php

declare(strict_types=1);

use App\Console\Commands\Topology\AcquireCommand;
use App\Console\Commands\Topology\ExecCommand;
use App\Console\Commands\Topology\ProveCommand;
use App\Console\Commands\Topology\ReapCommand;
use App\Console\Commands\Topology\ReleaseCommand;
use App\Console\Commands\Topology\SyncCommand;
use App\Console\Commands\Topology\VerifyCommand;

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
        $this
            ->artisan('topology:exec', ['issue' => 'NCK-12', 'role' => 'gateway', '--json' => true])
            ->expectsOutputToContain('"operation_id"')
            ->assertFailed();
    });
});
