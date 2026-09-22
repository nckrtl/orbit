<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\Registration\RegistrationSourceFacts;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppInstances\RemoteRegistrationSourceManager;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process as SymfonyProcess;

it('preserves an unowned legacy stage even when another registration copy verifies', function (bool $destinationPresent, string $kind): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $before = orb105_complete_manifest($fixture['source']);
        $stage = $fixture['destination'].'.orbit-stage-'.$fixture['instance']->id;
        if ($destinationPresent) {
            orb105_copy_tree($fixture['source'], $fixture['destination']);
        }
        if ($kind === 'matching checkout') {
            orb105_copy_tree($fixture['source'], $stage);
        } elseif ($kind === 'directory') {
            mkdir($stage);
            file_put_contents($stage.'/foreign.txt', "not registration content\n");
        } elseif ($kind === 'file') {
            file_put_contents($stage, "not registration content\n");
        } else {
            symlink($fixture['source'], $stage);
        }
        $stageBefore = orb105_complete_manifest($stage);

        expect(fn () => $fixture['manager']->relocate($fixture['instance'], $facts))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_incomplete');
            });

        expect(orb105_complete_manifest($stage))->toBe($stageBefore);
        expect(orb105_complete_manifest($fixture['source']))->toBe($before);
        expect(file_exists($fixture['destination']))->toBe($destinationPresent);
        if ($destinationPresent) {
            expect(orb105_complete_manifest($fixture['destination']))->toBe($before);
        }
        expect($fixture['instance']->refresh()->registration_relocation_state)->toBe('relocating');
        expect($fixture['instance']->registration_authoritative_path)->toBe($fixture['source']);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
})->with(['verified source' => false, 'verified destination' => true])
    ->with(['directory', 'matching checkout', 'file', 'symlink']);

it('resumes relocation from each durable cross-filesystem checkpoint', function (string $checkpoint): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $gitBefore = orb105_git_state($fixture['source']);
        $manifestBefore = orb105_complete_manifest($fixture['source']);
        $stage = $fixture['destination'].'.orbit-stage-'.$fixture['instance']->id;

        if (in_array($checkpoint, ['incomplete-stage', 'complete-stage'], strict: true)) {
            $interrupting = orb105_registration_manager(new Orb105InterruptStageSshExecutor($checkpoint));
            expect(fn () => $interrupting->relocate($fixture['instance'], $facts))
                ->toThrow(RuntimeConvergenceException::class);
            $stage = orb105_relocation_scope($fixture).'/stage';
            expect(is_dir($stage))->toBeTrue();
            expect($fixture['instance']->refresh()->registration_relocation_receipt)->not->toBeNull();
        } else {
            orb105_copy_tree($fixture['source'], $fixture['destination']);

            if ($checkpoint === 'destination-only') {
                new Filesystem()->deleteDirectory($fixture['source']);
            }
        }

        $fixture['manager']->relocate($fixture['instance'], $facts);

        expect(file_exists($fixture['source']))
            ->toBeFalse()
            ->and(file_exists($stage))
            ->toBeFalse()
            ->and(orb105_git_state($fixture['destination']))
            ->toBe($gitBefore)
            ->and(orb105_complete_manifest($fixture['destination']))
            ->toBe($manifestBefore)
            ->and(
                $fixture['instance']
                    ->refresh()
                    ->only([
                        'registration_relocation_state',
                        'registration_authoritative_path',
                    ]),
            )
            ->toBe([
                'registration_relocation_state' => 'relocated',
                'registration_authoritative_path' => $fixture['destination'],
            ]);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
})->with([
    'incomplete stage with the verified original' => 'incomplete-stage',
    'complete verified stage with the verified original' => 'complete-stage',
    'verified destination with a duplicate original' => 'destination-and-original',
    'verified destination after original removal before the database checkpoint' => 'destination-only',
]);

it('fails closed when an incomplete stage has no verified original', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $stage = $fixture['destination'].'.orbit-stage-'.$fixture['instance']->id;
        orb105_copy_tree($fixture['source'], $stage);
        file_put_contents($stage.'/README.md', "partial stage\n");
        new Filesystem()->deleteDirectory($fixture['source']);

        expect(fn () => $fixture['manager']->relocate($fixture['instance'], $facts))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_incomplete');
            })
            ->and(is_dir($stage))
            ->toBeTrue()
            ->and(file_exists($fixture['destination']))
            ->toBeFalse()
            ->and(
                $fixture['instance']
                    ->refresh()
                    ->only([
                        'registration_relocation_state',
                        'registration_authoritative_path',
                    ]),
            )
            ->toBe([
                'registration_relocation_state' => 'relocating',
                'registration_authoritative_path' => $fixture['source'],
            ]);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('persists no relocation ownership and moves no content when the Gateway receipt write fails', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $before = orb105_complete_manifest($fixture['source']);
        DB::unprepared("CREATE TRIGGER orb105_receipt_write_failure BEFORE UPDATE OF registration_relocation_receipt ON app_instances WHEN json_extract(NEW.registration_relocation_receipt, '$.scope') IS NOT NULL BEGIN SELECT RAISE(ABORT, 'receipt persistence stopped'); END");

        try {
            expect(fn () => $fixture['manager']->relocate($fixture['instance'], $facts))
                ->toThrow(QueryException::class);
        } finally {
            DB::unprepared('DROP TRIGGER orb105_receipt_write_failure');
        }

        expect($fixture['instance']->registration_relocation_receipt['scope'])->toBeNull();
        expect($fixture['instance']->refresh()->registration_relocation_receipt['scope'])->toBeNull();
        expect(orb105_complete_manifest($fixture['source']))->toBe($before);
        expect(file_exists($fixture['destination']))->toBeFalse();
        expect(scandir(orb105_relocation_scope($fixture)))->toBe(['.', '..', 'state.journal']);

        $fixture['manager']->relocate($fixture['instance'], $facts);

        expect(orb105_complete_manifest($fixture['destination']))->toBe($before);
        expect(file_exists($fixture['source']))->toBeFalse();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('recovers metadata initialization after its remote acknowledgment is lost without copying early', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $before = orb105_complete_manifest($fixture['source']);
        $interrupting = orb105_registration_manager(new Orb105InterruptAfterPrepareSshExecutor('initialize'));

        expect(fn () => $interrupting->relocate($fixture['instance'], $facts))
            ->toThrow(RuntimeConvergenceException::class);

        expect($fixture['instance']->refresh()->registration_relocation_receipt['scope'])->toBeNull();
        expect(orb105_complete_manifest($fixture['source']))->toBe($before);
        expect(file_exists($fixture['destination']))->toBeFalse();
        $scope = orb105_relocation_scope($fixture);
        $scopeIdentity = lstat($scope)['ino'];
        expect(scandir($scope))->toBe(['.', '..', 'state.journal']);

        $fixture['manager']->relocate($fixture['instance'], $facts);

        expect(lstat($scope)['ino'])->toBe($scopeIdentity);
        expect(orb105_complete_manifest($fixture['destination']))->toBe($before);
        expect(file_exists($fixture['source']))->toBeFalse();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('refuses invalid initialized ownership before moving content and retains the pending attempt', function (string $corruption): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $before = orb105_complete_manifest($fixture['source']);
        $manager = orb105_registration_manager(new Orb105InitializeCallbackSshExecutor(
            static function (CommandResult $result) use ($corruption): CommandResult {
                $receipts = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
                if ($corruption === 'attempt') {
                    $receipts[0]['attempt'] = (string) Str::uuid();
                } elseif ($corruption === 'scope') {
                    $receipts[0]['scope'] = ['device', 'inode'];
                } else {
                    $receipts[0]['id']++;
                }

                return new CommandResult(0, json_encode($receipts, JSON_THROW_ON_ERROR), '', $result->durationMs, false);
            },
        ));

        expect(fn () => $manager->relocate($fixture['instance'], $facts))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_incomplete');
            });

        $attempt = $fixture['instance']->refresh()->registration_relocation_receipt['attempt'];
        expect($fixture['instance']->registration_relocation_receipt['scope'])->toBeNull();
        expect(orb105_complete_manifest($fixture['source']))->toBe($before);
        expect(file_exists($fixture['destination']))->toBeFalse();
        expect(scandir(orb105_relocation_scope($fixture)))->toBe(['.', '..', 'state.journal']);

        $fixture['manager']->relocate($fixture['instance'], $facts);

        expect($fixture['instance']->refresh()->registration_relocation_receipt['attempt'])->toBe($attempt);
        expect(orb105_complete_manifest($fixture['destination']))->toBe($before);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
})->with(['attempt', 'scope', 'member']);

it('does not move source content when its Instance disappears before initialized ownership is saved', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $before = orb105_complete_manifest($fixture['source']);
        $manager = orb105_registration_manager(new Orb105InitializeCallbackSshExecutor(
            static function (CommandResult $result) use ($fixture): CommandResult {
                AppInstance::query()->whereKey($fixture['instance']->id)->delete();

                return $result;
            },
        ));

        expect(fn () => $manager->relocate($fixture['instance'], $facts))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_conflict');
            });

        $this->assertDatabaseMissing('app_instances', ['id' => $fixture['instance']->id]);
        expect(orb105_complete_manifest($fixture['source']))->toBe($before);
        expect(file_exists($fixture['destination']))->toBeFalse();
        expect(scandir(orb105_relocation_scope($fixture)))->toBe(['.', '..', 'state.journal']);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('refuses replaced or linked stage ownership boundaries without changing their content', function (string $replacement): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $before = orb105_complete_manifest($fixture['source']);
        $interrupting = orb105_registration_manager(new Orb105InterruptStageSshExecutor('complete-stage'));
        expect(fn () => $interrupting->relocate($fixture['instance'], $facts))
            ->toThrow(RuntimeConvergenceException::class);
        $scope = orb105_relocation_scope($fixture);
        if ($replacement === 'scope link' || $replacement === 'scope copy') {
            rename($scope, $scope.'-retained');
            if ($replacement === 'scope link') {
                symlink($scope.'-retained', $scope);
            } else {
                orb105_copy_tree($scope.'-retained', $scope);
            }
        } elseif ($replacement === 'parent link') {
            $parent = dirname($scope);
            rename($parent, $parent.'-retained');
            symlink($parent.'-retained', $parent);
        } elseif ($replacement === 'ancestor copy') {
            $ancestor = dirname(dirname($scope));
            rename($ancestor, $ancestor.'-retained');
            orb105_copy_tree($ancestor.'-retained', $ancestor);
        } else {
            rename($scope.'/state.journal', $scope.'/state-retained.journal');
            symlink($fixture['source'].'/README.md', $scope.'/state.journal');
        }
        $treeBefore = orb105_complete_manifest($fixture['destination_root']);

        expect(fn () => $fixture['manager']->relocate($fixture['instance']->refresh(), $facts))
            ->toThrow(RuntimeConvergenceException::class);

        expect(orb105_complete_manifest($fixture['destination_root']))->toBe($treeBefore);
        expect(orb105_complete_manifest($fixture['source']))->toBe($before);
        expect(file_exists($fixture['destination']))->toBeFalse();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
})->with(['scope link', 'scope copy', 'parent link', 'ancestor copy', 'receipt link']);

it('preserves a replacement stage instead of adopting its path or matching content', function (string $replacement): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $before = orb105_complete_manifest($fixture['source']);
        $interrupting = orb105_registration_manager(new Orb105InterruptStageSshExecutor('complete-stage'));
        expect(fn () => $interrupting->relocate($fixture['instance'], $facts))
            ->toThrow(RuntimeConvergenceException::class);
        $scope = orb105_relocation_scope($fixture);
        $retained = $fixture['destination_root'].'/retained-stage';
        rename($scope.'/stage', $retained);
        if ($replacement === 'checkout') {
            orb105_copy_tree($fixture['source'], $scope.'/stage');
        } elseif ($replacement === 'file') {
            file_put_contents($scope.'/stage', "foreign file\n");
        } else {
            symlink($fixture['source'], $scope.'/stage');
        }
        $replacementBefore = orb105_complete_manifest($scope.'/stage');
        $retainedBefore = orb105_complete_manifest($retained);

        expect(fn () => $fixture['manager']->relocate($fixture['instance']->refresh(), $facts))
            ->toThrow(RuntimeConvergenceException::class);

        expect(orb105_complete_manifest($scope.'/stage'))->toBe($replacementBefore);
        expect(orb105_complete_manifest($retained))->toBe($retainedBefore);
        expect(orb105_complete_manifest($fixture['source']))->toBe($before);
        expect(file_exists($fixture['destination']))->toBeFalse();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
})->with(['checkout', 'file', 'symlink']);

it('rechecks a native stage claim after the validated pathname is replaced', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $before = orb105_complete_manifest($fixture['source']);
        $interrupting = orb105_registration_manager(new Orb105InterruptStageSshExecutor('complete-stage'));
        expect(fn () => $interrupting->relocate($fixture['instance'], $facts))
            ->toThrow(RuntimeConvergenceException::class);
        $scope = orb105_relocation_scope($fixture);
        file_put_contents($scope.'/stage/README.md', "incomplete owned copy\n");
        $ownedBefore = orb105_complete_manifest($scope.'/stage');
        $hook = <<<'PYTHON'
            import os, sys
            def substitute_stage(frame, event, result):
                if event == 'call' and frame.f_code.co_name == 'rename_exclusive' and frame.f_locals.get('source') == 'stage' and frame.f_locals.get('destination') == 'claimed':
                    sys.settrace(None)
                    parent=frame.f_locals['source_parent']
                    os.rename('stage','retained-stage',src_dir_fd=parent,dst_dir_fd=parent)
                    os.mkdir('stage',dir_fd=parent)
                    stage=os.open('stage',os.O_RDONLY | os.O_DIRECTORY,dir_fd=parent)
                    file=os.open('foreign.txt',os.O_WRONLY | os.O_CREAT | os.O_EXCL,0o600,dir_fd=stage)
                    os.write(file,b'foreign replacement\n'); os.close(file); os.close(stage)
                return substitute_stage
            sys.settrace(substitute_stage)
            PYTHON;
        $racing = orb105_registration_manager(new Orb105NativeHookSshExecutor('prepare', $hook));

        expect(fn () => $racing->relocate($fixture['instance']->refresh(), $facts))
            ->toThrow(RuntimeConvergenceException::class);

        expect(file_get_contents($scope.'/stage/foreign.txt'))->toBe("foreign replacement\n");
        expect(orb105_complete_manifest($scope.'/retained-stage'))->toBe($ownedBefore);
        expect(file_exists($scope.'/claimed'))->toBeFalse();
        expect(orb105_complete_manifest($fixture['source']))->toBe($before);
        expect(file_exists($fixture['destination']))->toBeFalse();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('refuses a private stage changed between its initial observation and opening without copying into it', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $before = orb105_complete_manifest($fixture['source']);
        $hook = <<<'PYTHON'
            import os, sys
            changed=False
            def replace_observed_stage(event,arguments):
                global changed
                if not changed and event == 'open' and arguments[0] == 'stage':
                    frame=sys._getframe(1)
                    if frame.f_code.co_name == 'copy':
                        changed=True
                        parent=frame.f_locals['self'].directory
                        os.rename('stage','retained-empty-stage',src_dir_fd=parent,dst_dir_fd=parent)
                        os.mkdir('stage',dir_fd=parent)
                        stage=os.open('stage',os.O_RDONLY | os.O_DIRECTORY,dir_fd=parent)
                        file=os.open('foreign.txt',os.O_WRONLY | os.O_CREAT | os.O_EXCL,0o600,dir_fd=stage)
                        os.write(file,b'never copied into\n'); os.close(file); os.close(stage)
            sys.addaudithook(replace_observed_stage)
            PYTHON;
        $racing = orb105_registration_manager(new Orb105NativeHookSshExecutor('prepare', $hook));

        expect(fn () => $racing->relocate($fixture['instance'], $facts))
            ->toThrow(RuntimeConvergenceException::class);

        $scope = orb105_relocation_scope($fixture);
        expect(scandir($scope.'/retained-empty-stage'))->toBe(['.', '..']);
        expect(scandir($scope.'/stage'))->toBe(['.', '..', 'foreign.txt']);
        expect(file_get_contents($scope.'/stage/foreign.txt'))->toBe("never copied into\n");
        expect(orb105_complete_manifest($fixture['source']))->toBe($before);
        expect(file_exists($fixture['destination']))->toBeFalse();
        $receipt = orb105_relocation_state($scope);
        expect($receipt['stage'])->toBeNull();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('resumes owned stage cleanup after native interruption without recopying over a foreign path', function (string $checkpoint): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $before = orb105_complete_manifest($fixture['source']);
        $interrupting = orb105_registration_manager(new Orb105InterruptStageSshExecutor('complete-stage'));
        expect(fn () => $interrupting->relocate($fixture['instance'], $facts))
            ->toThrow(RuntimeConvergenceException::class);
        $scope = orb105_relocation_scope($fixture);
        file_put_contents($scope.'/stage/README.md', "incomplete owned copy\n");
        $ownedBefore = orb105_complete_manifest($scope.'/stage');
        $hook = $checkpoint === 'partial tree'
            ? <<<'PYTHON'
                import os, sys
                removed=0
                def interrupt_deletion(event, arguments):
                    global removed
                    if event == 'os.remove' and '/claimed' in os.readlink('/proc/self/fd/'+str(arguments[1])):
                        removed+=1
                        if removed == 2: os._exit(137)
                sys.addaudithook(interrupt_deletion)
                PYTHON
            : <<<'PYTHON'
                import os, sys
                def interrupt_deleted_claim(frame,event,result):
                    if event == 'call' and frame.f_code.co_name == 'save':
                        scope=frame.f_locals['self']
                        if scope.state['phase'] == 'cleaned': os._exit(137)
                    return interrupt_deleted_claim
                sys.settrace(interrupt_deleted_claim)
                PYTHON;
        $cleanupInterrupting = orb105_registration_manager(new Orb105NativeHookSshExecutor('prepare', $hook));

        expect(fn () => $cleanupInterrupting->relocate($fixture['instance']->refresh(), $facts))
            ->toThrow(RuntimeConvergenceException::class);

        $receipt = orb105_relocation_state($scope);
        expect($receipt['phase'])->toBe('claimed');
        expect(file_exists($scope.'/stage'))->toBeFalse();
        if ($checkpoint === 'partial tree') {
            expect(is_dir($scope.'/claimed'))->toBeTrue();
            expect(orb105_complete_manifest($scope.'/claimed'))->not->toBe($ownedBefore);
        } else {
            expect(file_exists($scope.'/claimed'))->toBeFalse();
        }
        expect(orb105_complete_manifest($fixture['source']))->toBe($before);

        $fixture['manager']->relocate($fixture['instance']->refresh(), $facts);

        expect(orb105_complete_manifest($fixture['destination']))->toBe($before);
        expect(file_exists($fixture['source']))->toBeFalse();
        expect(file_exists($scope.'/stage'))->toBeFalse();
        expect(file_exists($scope.'/claimed'))->toBeFalse();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
})->with(['partial tree', 'deleted claim acknowledgment']);

it('preserves foreign claim contents and refuses an interrupted owned stage cleanup retry', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $before = orb105_complete_manifest($fixture['source']);
        $interrupting = orb105_registration_manager(new Orb105InterruptStageSshExecutor('complete-stage'));
        expect(fn () => $interrupting->relocate($fixture['instance'], $facts))
            ->toThrow(RuntimeConvergenceException::class);
        $scope = orb105_relocation_scope($fixture);
        file_put_contents($scope.'/stage/README.md', "incomplete owned copy\n");
        mkdir($scope.'/claimed');
        file_put_contents($scope.'/claimed/foreign.txt', "preserved\n");
        $scopeBefore = orb105_complete_manifest($scope);

        expect(fn () => $fixture['manager']->relocate($fixture['instance']->refresh(), $facts))
            ->toThrow(RuntimeConvergenceException::class);

        expect(orb105_complete_manifest($scope))->toBe($scopeBefore);
        expect(orb105_complete_manifest($fixture['source']))->toBe($before);
        expect(file_exists($fixture['destination']))->toBeFalse();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('recovers the last durable stage phase after an actual interrupted journal write', function (bool $completeWrite): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $before = orb105_complete_manifest($fixture['source']);
        $interrupting = orb105_registration_manager(new Orb105InterruptStageSshExecutor('complete-stage'));
        expect(fn () => $interrupting->relocate($fixture['instance'], $facts))
            ->toThrow(RuntimeConvergenceException::class);
        $scope = orb105_relocation_scope($fixture);
        $journalIdentity = lstat($scope.'/state.journal')['ino'];
        file_put_contents($scope.'/stage/README.md', "incomplete owned copy\n");
        $hook = 'complete_write='.($completeWrite ? 'True' : 'False')."\n".<<<'PYTHON'
            import os
            native_pwrite=os.pwrite
            def interrupt_checkpoint(descriptor,content,offset):
                if b'"phase":"cleaned"' in content:
                    native_pwrite(descriptor,content if complete_write else content[:24],offset)
                    os.fsync(descriptor)
                    os._exit(137)
                return native_pwrite(descriptor,content,offset)
            os.pwrite=interrupt_checkpoint
            PYTHON;
        $journalInterrupting = orb105_registration_manager(new Orb105NativeHookSshExecutor('prepare', $hook));

        expect(fn () => $journalInterrupting->relocate($fixture['instance']->refresh(), $facts))
            ->toThrow(RuntimeConvergenceException::class);

        expect(file_exists($scope.'/stage'))->toBeFalse();
        expect(file_exists($scope.'/claimed'))->toBeFalse();
        expect(orb105_relocation_state($scope)['phase'])->toBe($completeWrite ? 'cleaned' : 'claimed');
        expect(orb105_complete_manifest($fixture['source']))->toBe($before);

        $fixture['manager']->relocate($fixture['instance']->refresh(), $facts);

        expect(orb105_complete_manifest($fixture['destination']))->toBe($before);
        expect(file_exists($fixture['source']))->toBeFalse();
        expect(lstat($scope.'/state.journal')['ino'])->toBe($journalIdentity);
        expect(filesize($scope.'/state.journal'))->toBe(32_768);
        expect(scandir($scope))->toBe(['.', '..', 'state.journal']);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
})->with(['partial checkpoint' => false, 'lost completed checkpoint acknowledgment' => true]);

it('refuses an ambiguous first receipt write without touching the source', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $before = orb105_complete_manifest($fixture['source']);
        $hook = <<<'PYTHON'
            import os
            native_pwrite=os.pwrite
            def interrupt_initial_checkpoint(descriptor,content,offset):
                native_pwrite(descriptor,content[:24],offset)
                os.fsync(descriptor)
                os._exit(137)
            os.pwrite=interrupt_initial_checkpoint
            PYTHON;
        $interrupting = orb105_registration_manager(new Orb105NativeHookSshExecutor('initialize', $hook));
        expect(fn () => $interrupting->relocate($fixture['instance'], $facts))
            ->toThrow(RuntimeConvergenceException::class);
        $scope = orb105_relocation_scope($fixture);
        $scopeBefore = orb105_complete_manifest($scope);

        expect(fn () => $fixture['manager']->relocate($fixture['instance']->refresh(), $facts))
            ->toThrow(RuntimeConvergenceException::class);

        expect(orb105_complete_manifest($fixture['source']))->toBe($before);
        expect(orb105_complete_manifest($scope))->toBe($scopeBefore);
        expect(file_exists($fixture['destination']))->toBeFalse();
        expect($fixture['instance']->refresh()->registration_relocation_receipt['scope'])->toBeNull();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('restores a substituted same-filesystem source after the native rename rejects its identity', function (bool $recreatedSource): void {
    $fixture = orb105_relocation_fixture(crossFilesystem: false);

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $before = orb105_complete_manifest($fixture['source']);
        $hook = 'recreate_source='.($recreatedSource ? 'True' : 'False')."\n".<<<'PYTHON'
            import os, sys
            def substitute_source(frame,event,result):
                if event == 'call' and frame.f_code.co_name == 'rename_exclusive' and frame.f_locals.get('source') == 'acme':
                    parent=frame.f_locals['source_parent']
                    os.rename('acme','retained-original',src_dir_fd=parent,dst_dir_fd=parent)
                    os.mkdir('acme',dir_fd=parent)
                    source=os.open('acme',os.O_RDONLY | os.O_DIRECTORY,dir_fd=parent)
                    file=os.open('foreign.txt',os.O_WRONLY | os.O_CREAT | os.O_EXCL,0o600,dir_fd=source)
                    os.write(file,b'foreign moved entry\n'); os.close(file); os.close(source)
                elif event == 'return' and frame.f_code.co_name == 'rename_exclusive' and frame.f_locals.get('source') == 'acme':
                    sys.settrace(None)
                    if recreate_source:
                        parent=frame.f_locals['source_parent']
                        os.mkdir('acme',dir_fd=parent)
                        source=os.open('acme',os.O_RDONLY | os.O_DIRECTORY,dir_fd=parent)
                        file=os.open('recreated.txt',os.O_WRONLY | os.O_CREAT | os.O_EXCL,0o600,dir_fd=source)
                        os.write(file,b'keep recreated source\n'); os.close(file); os.close(source)
                return substitute_source
            sys.settrace(substitute_source)
            PYTHON;
        $racing = orb105_registration_manager(new Orb105NativeHookSshExecutor('prepare', $hook));

        expect(fn () => $racing->relocate($fixture['instance'], $facts))
            ->toThrow(RuntimeConvergenceException::class);

        expect(orb105_complete_manifest(dirname($fixture['source']).'/retained-original'))->toBe($before);
        if ($recreatedSource) {
            expect(file_get_contents($fixture['source'].'/recreated.txt'))->toBe("keep recreated source\n");
            expect(file_get_contents($fixture['destination'].'/foreign.txt'))->toBe("foreign moved entry\n");
        } else {
            expect(file_get_contents($fixture['source'].'/foreign.txt'))->toBe("foreign moved entry\n");
            expect(file_exists($fixture['destination']))->toBeFalse();
        }
        expect($fixture['instance']->refresh()->registration_relocation_state)->toBe('relocating');
        expect(orb105_relocation_state(orb105_relocation_scope($fixture))['phase'])->toBe('moving');
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
})->with(['safe restoration' => false, 'restoration conflicts with recreated source' => true]);

it('refuses a relocated destination parent after prepare even when a matching destination exists', function (bool $moveScope): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $before = orb105_complete_manifest($fixture['source']);
        $interrupting = orb105_registration_manager(new Orb105InterruptAfterPrepareSshExecutor);
        expect(fn () => $interrupting->relocate($fixture['instance'], $facts))
            ->toThrow(RuntimeConvergenceException::class);
        $scope = orb105_relocation_scope($fixture);
        $parent = dirname($fixture['destination']);
        rename($parent, $parent.'-retained');
        mkdir($parent);
        orb105_copy_tree($fixture['source'], $fixture['destination']);
        if ($moveScope) {
            rename($parent.'-retained/'.basename($scope), $scope);
        }
        $retainedBefore = orb105_complete_manifest($parent.'-retained');
        $replacementBefore = orb105_complete_manifest($parent);

        expect(fn () => $fixture['manager']->relocate($fixture['instance']->refresh(), $facts))
            ->toThrow(RuntimeConvergenceException::class);

        expect(orb105_complete_manifest($parent.'-retained'))->toBe($retainedBefore);
        expect(orb105_complete_manifest($parent))->toBe($replacementBefore);
        expect(orb105_complete_manifest($fixture['source']))->toBe($before);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
})->with(['scope moved away' => false, 'scope transplanted' => true]);

it('resumes after an emitted same-filesystem rename outruns its database checkpoint', function (): void {
    $fixture = orb105_relocation_fixture(crossFilesystem: false);

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $gitBefore = orb105_git_state($fixture['source']);
        $manifestBefore = orb105_complete_manifest($fixture['source']);
        $interruptingManager = orb105_registration_manager(new Orb105InterruptAfterPrepareSshExecutor);

        expect(fn () => $interruptingManager->relocate($fixture['instance'], $facts))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_incomplete');
            })
            ->and(file_exists($fixture['source']))
            ->toBeFalse()
            ->and(orb105_git_state($fixture['destination']))
            ->toBe($gitBefore)
            ->and(orb105_complete_manifest($fixture['destination']))
            ->toBe($manifestBefore)
            ->and($fixture['instance']->refresh()->registration_relocation_state)
            ->toBe('relocating')
            ->and($fixture['instance']->registration_authoritative_path)
            ->toBe($fixture['source']);

        $fixture['manager']->validateRelocationRecovery(
            $fixture['node'],
            $facts,
            $fixture['destination'],
        );
        $fixture['manager']->relocate($fixture['instance']->refresh(), $facts);

        expect(file_exists($fixture['source']))
            ->toBeFalse()
            ->and(orb105_git_state($fixture['destination']))
            ->toBe($gitBefore)
            ->and(orb105_complete_manifest($fixture['destination']))
            ->toBe($manifestBefore)
            ->and($fixture['instance']->refresh()->registration_relocation_state)
            ->toBe('relocated')
            ->and($fixture['instance']->registration_authoritative_path)
            ->toBe($fixture['destination']);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('resumes a partially renamed included worktree set from retained evidence', function (): void {
    $fixture = orb105_relocation_fixture(crossFilesystem: false);

    try {
        $linkedSource = $fixture['source_root'].'/source/feature';
        $linkedDestination = $fixture['destination_root'].'/managed/acme/feature';
        orb105_run(['git', '-C', $fixture['source'], 'worktree', 'add', '-b', 'feature', $linkedSource]);
        $files = new Filesystem;
        $files->ensureDirectoryExists($linkedSource.'/many');
        for ($index = 0; $index < 2_000; $index++) {
            file_put_contents($linkedSource.'/many/'.$index, "retained\n");
        }

        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], true);
        $requestId = $fixture['instance']->registration_request_id;
        $linked = AppInstance::query()->create([
            'app_id' => $fixture['instance']->app_id,
            'node_id' => $fixture['node']->id,
            'name' => 'feature',
            'source_layout' => 'worktree',
            'checkout_path' => $linkedDestination,
            'branch' => 'feature',
            'starting_commit' => collect($facts)->firstWhere('path', $linkedSource)?->commit,
            'registration_original_path' => $linkedSource,
            'registration_request_id' => $requestId,
            'registration_repository_url' => 'https://example.test/acme.git',
            'registration_repository_identity' => 'example.test/acme',
            'registration_relocation_state' => 'reserved',
            'registration_authoritative_path' => $linkedSource,
            'status' => 'reserved',
        ]);
        $members = array_map(
            static fn (RegistrationSourceFacts $fact): array => [
                'appInstance' => $fact->path === $fixture['source'] ? $fixture['instance'] : $linked,
                'facts' => $fact,
            ],
            $facts,
        );
        $manifests = [];
        foreach ($facts as $fact) {
            $manifests[$fact->path] = orb105_preserved_manifest($fact->path);
        }
        $interrupting = orb105_registration_manager(new Orb105InterruptPartialPrepareSshExecutor(
            movedSource: $linkedSource,
            unmovedSource: $fixture['source'],
        ));

        expect(fn () => $interrupting->relocateSet($members))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_incomplete');
            })
            ->and(file_exists($linkedSource))
            ->toBeFalse()
            ->and(is_dir($linkedDestination))
            ->toBeTrue()
            ->and(is_dir($fixture['source']))
            ->toBeTrue()
            ->and($fixture['instance']->refresh()->registration_relocation_state)
            ->toBe('relocating')
            ->and($linked->refresh()->registration_relocation_state)
            ->toBe('relocating');

        foreach ($facts as $fact) {
            $fixture['manager']->validateRelocationRecovery(
                $fixture['node'],
                $fact,
                $fact->path === $linkedSource ? $linkedDestination : $fixture['source'],
            );
        }

        $fixture['manager']->relocateSet($members);

        expect(file_exists($fixture['source']))
            ->toBeFalse()
            ->and(file_exists($linkedSource))
            ->toBeFalse()
            ->and(orb105_preserved_manifest($fixture['destination']))
            ->toBe($manifests[$fixture['source']])
            ->and(orb105_preserved_manifest($linkedDestination))
            ->toBe($manifests[$linkedSource])
            ->and($fixture['instance']->refresh()->registration_relocation_state)
            ->toBe('relocated')
            ->and($linked->refresh()->registration_relocation_state)
            ->toBe('relocated');
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('preflights stage ownership for every included member before moving any source', function (): void {
    $fixture = orb105_relocation_fixture(crossFilesystem: false);

    try {
        $linkedSource = $fixture['source_root'].'/source/feature';
        $linkedDestination = $fixture['destination_root'].'/managed/acme/feature';
        orb105_run(['git', '-C', $fixture['source'], 'worktree', 'add', '-b', 'feature', $linkedSource]);
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], true);
        $linked = AppInstance::query()->create([
            'app_id' => $fixture['instance']->app_id,
            'node_id' => $fixture['node']->id,
            'name' => 'feature',
            'source_layout' => 'worktree',
            'checkout_path' => $linkedDestination,
            'branch' => 'feature',
            'registration_original_path' => $linkedSource,
            'registration_request_id' => $fixture['instance']->registration_request_id,
            'registration_relocation_state' => 'reserved',
            'registration_authoritative_path' => $linkedSource,
            'status' => 'reserved',
        ]);
        $members = array_map(
            static fn (RegistrationSourceFacts $fact): array => [
                'appInstance' => $fact->path === $fixture['source'] ? $fixture['instance'] : $linked,
                'facts' => $fact,
            ],
            $facts,
        );
        $stage = $linkedDestination.'.orbit-stage-'.$linked->id;
        mkdir($stage);
        file_put_contents($stage.'/foreign.txt', "foreign stage\n");
        $before = orb105_complete_manifest($fixture['source']);
        $linkedBefore = orb105_complete_manifest($linkedSource);
        $stageBefore = orb105_complete_manifest($stage);

        expect(fn () => $fixture['manager']->relocateSet($members))
            ->toThrow(RuntimeConvergenceException::class);

        expect(orb105_complete_manifest($fixture['source']))->toBe($before);
        expect(orb105_complete_manifest($linkedSource))->toBe($linkedBefore);
        expect(orb105_complete_manifest($stage))->toBe($stageBefore);
        expect(file_exists($fixture['destination']))->toBeFalse();
        expect(file_exists($linkedDestination))->toBeFalse();
        expect($fixture['instance']->refresh()->registration_relocation_receipt['scope'])->toBeNull();
        expect($linked->refresh()->registration_relocation_receipt['scope'])->toBeNull();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('uses distinct ownership receipts when restoring a relocated source to its original path', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $before = orb105_complete_manifest($fixture['source']);
        $fixture['manager']->relocate($fixture['instance'], $facts);
        $forwardScope = orb105_relocation_scope($fixture);
        $forwardBefore = orb105_complete_manifest($forwardScope);
        $forwardReceipt = $fixture['instance']->refresh()->registration_relocation_receipt;

        $fixture['manager']->restoreOriginal($fixture['instance'], $facts);

        expect(orb105_complete_manifest($fixture['source']))->toBe($before);
        expect(file_exists($fixture['destination']))->toBeFalse();
        expect(orb105_complete_manifest($forwardScope))->toBe($forwardBefore);
        $restored = $fixture['instance']->refresh();
        expect($restored->checkout_path)->toBe($fixture['destination']);
        expect($restored->registration_authoritative_path)->toBe($fixture['source']);
        expect($restored->registration_relocation_receipt)->not->toBe($forwardReceipt);
        expect($restored->registration_relocation_receipt['source'])->toBe($fixture['destination']);
        expect($restored->registration_relocation_receipt['destination'])->toBe($fixture['source']);
        $reverseAttempt = $restored->registration_relocation_receipt['attempt'];

        $fixture['manager']->relocate($restored, $facts);

        expect(orb105_complete_manifest($fixture['destination']))->toBe($before);
        expect(file_exists($fixture['source']))->toBeFalse();
        expect($restored->refresh()->registration_relocation_receipt['attempt'])->not->toBe($reverseAttempt);
        expect($restored->registration_relocation_receipt['attempt'])->not->toBe($forwardReceipt['attempt']);
        expect(orb105_complete_manifest($forwardScope))->toBe($forwardBefore);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('refuses unsafe checkout and Git administration modes before relocation', function (string $target): void {
    $fixture = orb105_relocation_fixture();

    try {
        chmod($target === 'checkout' ? $fixture['source'] : $fixture['source'].'/.git', 0o777);

        expect(fn () => $fixture['manager']->inspect($fixture['node'], $fixture['source'], false))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.source_invalid');
            })
            ->and(is_dir($fixture['source']))
            ->toBeTrue()
            ->and(file_exists($fixture['destination']))
            ->toBeFalse()
            ->and($fixture['instance']->refresh()->registration_relocation_state)
            ->toBe('reserved');
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
})->with(['checkout', 'Git administration']);

it('refuses an invalid second non-Composer worktree before any included source moves', function (): void {
    $fixture = orb105_relocation_fixture(crossFilesystem: false);

    try {
        $linkedSource = $fixture['source_root'].'/source/feature';
        orb105_run(['git', '-C', $fixture['source'], 'worktree', 'add', '-b', 'feature', $linkedSource]);
        chmod($linkedSource.'/.git', 0o666);

        expect(fn () => $fixture['manager']->inspect($fixture['node'], $fixture['source'], true))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.source_invalid');
            })
            ->and(is_dir($fixture['source']))
            ->toBeTrue()
            ->and(is_dir($linkedSource))
            ->toBeTrue()
            ->and(file_exists($fixture['destination']))
            ->toBeFalse()
            ->and(file_exists($fixture['destination_root'].'/managed/acme/feature'))
            ->toBeFalse();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('refuses a non-Composer checkout outside the resolved managed group', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $manager = orb105_registration_manager(new Orb105LocalSshExecutor, managedGroup: 'root');

        expect(fn () => $manager->inspect($fixture['node'], $fixture['source'], false))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.source_invalid');
            })
            ->and(is_dir($fixture['source']))
            ->toBeTrue()
            ->and(file_exists($fixture['destination']))
            ->toBeFalse();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('persists relocation checkpoints without publishing dirty migration fields', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        AppInstance::query()
            ->whereKey($fixture['instance']->id)
            ->update([
                'name' => 'main',
                'checkout_path' => $fixture['source'],
                'migration_required' => true,
            ]);
        $fixture['instance']->refresh();
        $fixture['instance']->name = 'default';
        $fixture['instance']->checkout_path = $fixture['destination'];

        $fixture['manager']->relocate($fixture['instance'], $facts);

        expect($fixture['instance']
            ->refresh()
            ->only([
                'name',
                'checkout_path',
                'migration_required',
                'registration_relocation_state',
                'registration_authoritative_path',
            ]))->toBe([
                'name' => 'main',
                'checkout_path' => $fixture['source'],
                'migration_required' => true,
                'registration_relocation_state' => 'relocated',
                'registration_authoritative_path' => $fixture['destination'],
            ]);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('does not reapply source-digest relocation after the managed destination becomes authoritative', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $fixture['manager']->relocate($fixture['instance'], $facts);
        file_put_contents($fixture['destination'].'/.env', "APP_URL=https://managed.test\n");
        file_put_contents($fixture['destination'].'/README.md', "normal development edit\n", FILE_APPEND);
        $afterEdits = orb105_complete_manifest($fixture['destination']);

        $fixture['manager']->validateRetained($fixture['node'], $facts, $fixture['destination']);
        $fixture['manager']->relocate($fixture['instance']->refresh(), $facts);

        expect(orb105_complete_manifest($fixture['destination']))
            ->toBe($afterEdits)
            ->and(file_exists($fixture['source']))
            ->toBeFalse()
            ->and($fixture['instance']->refresh()->registration_relocation_state)
            ->toBe('relocated')
            ->and($fixture['instance']->registration_authoritative_path)
            ->toBe($fixture['destination']);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('refuses a replacement repository at a retained authoritative destination', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $fixture['manager']->relocate($fixture['instance'], $facts);
        orb105_run([
            'git',
            '-C',
            $fixture['destination'],
            'remote',
            'set-url',
            'origin',
            'https://example.test/replacement.git',
        ]);

        expect(fn () => $fixture['manager']->validateRetained(
            $fixture['node'],
            $facts,
            $fixture['destination'],
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.registration_conflict');
        });
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('resumes an actual interruption during original cleanup from the verified destination', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $files = new Filesystem;
        $files->ensureDirectoryExists($fixture['source'].'/cleanup');
        file_put_contents($fixture['source'].'/cleanup/00000-trigger', "trigger\n");
        for ($index = 1; $index <= 20_000; $index++) {
            file_put_contents(
                $fixture['source'].'/cleanup/'.str_pad((string) $index, 5, '0', STR_PAD_LEFT),
                "retained\n",
            );
        }
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $manifestBefore = orb105_complete_manifest($fixture['source']);
        $interruptingManager = orb105_registration_manager(
            new Orb105InterruptingCleanupSshExecutor(
                $fixture['source'],
                $fixture['source'].'/cleanup/00000-trigger',
            ),
        );

        expect(fn () => $interruptingManager->relocate($fixture['instance'], $facts))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_incomplete');
            });

        $partialCount = count(glob($fixture['source'].'/cleanup/*') ?: []);
        $checkpoint = $fixture['instance']->refresh();
        expect(is_dir($fixture['source']))
            ->toBeTrue()
            ->and($partialCount)
            ->toBeLessThan(20_001)
            ->and(orb105_complete_manifest($fixture['source']))
            ->not
            ->toBe($manifestBefore)
            ->and(orb105_complete_manifest($fixture['destination']))
            ->toBe($manifestBefore)
            ->and($checkpoint->registration_relocation_state)
            ->toBe('original_cleanup')
            ->and($checkpoint->registration_authoritative_path)
            ->toBe($fixture['destination'])
            ->and($checkpoint->registration_source_device)
            ->toBeInt()
            ->and($checkpoint->registration_source_inode)
            ->toBeInt();

        $instanceId = $checkpoint->id;
        $fixture['manager']->relocate($checkpoint, $facts);

        expect(file_exists($fixture['source']))
            ->toBeFalse()
            ->and(orb105_complete_manifest($fixture['destination']))
            ->toBe($manifestBefore)
            ->and($fixture['instance']->refresh()->id)
            ->toBe($instanceId)
            ->and($fixture['instance']->registration_relocation_state)
            ->toBe('relocated')
            ->and($fixture['instance']->registration_authoritative_path)
            ->toBe($fixture['destination']);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('refuses cleanup when the original path was replaced after destination verification', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $sourceIdentity = stat($fixture['source']);
        orb105_copy_tree($fixture['source'], $fixture['destination']);
        $fixture['instance']->update([
            'registration_relocation_state' => 'original_cleanup',
            'registration_authoritative_path' => $fixture['destination'],
            'registration_source_device' => $sourceIdentity['dev'],
            'registration_source_inode' => $sourceIdentity['ino'],
        ]);
        new Filesystem()->deleteDirectory($fixture['source']);
        new Filesystem()->ensureDirectoryExists($fixture['source']);
        file_put_contents($fixture['source'].'/unrelated.txt', "unrelated\n");

        expect(fn () => $fixture['manager']->relocate($fixture['instance']->refresh(), $facts))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_incomplete');
            })
            ->and(file_get_contents($fixture['source'].'/unrelated.txt'))
            ->toBe("unrelated\n")
            ->and(is_dir($fixture['destination']))
            ->toBeTrue();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('restores Laravel URL files and the stable directory timestamps they touch', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $files = new Filesystem;
        $files->ensureDirectoryExists($fixture['source'].'/bootstrap/cache');
        file_put_contents($fixture['source'].'/.env', "APP_URL=https://before.test\n");
        file_put_contents($fixture['source'].'/bootstrap/cache/config.php', "<?php return ['url' => 'before'];\n");
        $fixture['instance']->update(['checkout_path' => $fixture['source']]);
        $before = orb105_complete_manifest($fixture['source']);

        $fixture['manager']->prepareLaravelRollback($fixture['instance']);
        file_put_contents($fixture['source'].'/.env.next', "APP_URL=https://after.test\n");
        rename($fixture['source'].'/.env.next', $fixture['source'].'/.env');
        file_put_contents($fixture['source'].'/bootstrap/cache/config.php.next', "<?php return ['url' => 'after'];\n");
        rename(
            $fixture['source'].'/bootstrap/cache/config.php.next',
            $fixture['source'].'/bootstrap/cache/config.php',
        );
        $fixture['manager']->prepareLaravelRollback($fixture['instance']);
        $fixture['manager']->restoreLaravelConfiguration($fixture['instance']);

        expect(orb105_complete_manifest($fixture['source']))->toBe($before);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('refuses an incomplete Laravel rollback receipt without replacing it', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $receipt = dirname($fixture['source']).'/.orbit-registration-url-'.$fixture['instance']->id;
        new Filesystem()->ensureDirectoryExists($receipt);
        file_put_contents($receipt.'/0', "partial\n");
        $fixture['instance']->update(['checkout_path' => $fixture['source']]);

        expect(fn () => $fixture['manager']->prepareLaravelRollback($fixture['instance']))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.laravel_rollback_failed');
            })
            ->and(file_get_contents($receipt.'/0'))
            ->toBe("partial\n")
            ->and(file_exists($receipt.'/manifest'))
            ->toBeFalse();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

/**
 * @return array{
 *     manager: RemoteRegistrationSourceManager,
 *     node: Node,
 *     instance: AppInstance,
 *     source_root: string,
 *     destination_root: string,
 *     source: string,
 *     destination: string
 * }
 */
function orb105_relocation_fixture(bool $crossFilesystem = true): array
{
    $token = (string) Str::uuid();
    $sourceRoot = $crossFilesystem
        ? '/dev/shm/orbit-orb105-'.$token
        : sys_get_temp_dir().'/orbit-orb105-'.$token;
    $destinationRoot = $crossFilesystem
        ? sys_get_temp_dir().'/orbit-orb105-'.$token
        : $sourceRoot;
    $source = $sourceRoot.($crossFilesystem ? '/acme' : '/source/acme');
    $destination = $destinationRoot.'/managed/acme/default';
    $files = new Filesystem;
    $files->ensureDirectoryExists($source);
    $files->ensureDirectoryExists(dirname($destination));

    if ($crossFilesystem) {
        expect(stat($sourceRoot)['dev'])->not->toBe(stat($destinationRoot)['dev']);
    } else {
        expect(stat($sourceRoot)['dev'])->toBe(stat($destinationRoot)['dev']);
    }

    orb105_run(['git', 'init', '--initial-branch=main', $source]);
    orb105_run(['git', '-C', $source, 'config', 'user.email', 'orb105@example.test']);
    orb105_run(['git', '-C', $source, 'config', 'user.name', 'ORB-105']);
    orb105_run(['git', '-C', $source, 'config', 'orbit.fixture', 'preserved']);
    file_put_contents($source.'/README.md', "initial\n");
    $files->ensureDirectoryExists($source.'/bin');
    file_put_contents($source.'/bin/run', "#!/bin/sh\nexit 0\n");
    chmod($source.'/bin/run', 0o750);
    symlink('README.md', $source.'/readme-link');
    orb105_run(['git', '-C', $source, 'add', '.']);
    orb105_run(['git', '-C', $source, 'commit', '-m', 'Initial']);
    orb105_run(['git', '-C', $source, 'remote', 'add', 'origin', 'https://example.test/acme.git']);
    file_put_contents($source.'/README.md', "dirty\n", FILE_APPEND);
    file_put_contents($source.'/staged.txt', "staged\n");
    orb105_run(['git', '-C', $source, 'add', 'staged.txt']);
    file_put_contents($source.'/untracked.txt', "untracked\n");

    $node = Node::query()->create([
        'name' => 'orb105-local',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'test',
        'public_ssh_host' => '192.0.2.105',
        'wireguard_ip' => '127.0.0.1',
        'user' => get_current_user(),
        'settings' => ['apps' => ['path' => $destinationRoot.'/managed']],
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => null,
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'default',
        'source_layout' => 'checkout',
        'checkout_path' => $destination,
        'branch' => 'main',
        'registration_original_path' => $source,
        'registration_request_id' => (string) Str::uuid(),
        'registration_primary' => true,
        'registration_repository_url' => 'https://example.test/acme.git',
        'registration_repository_identity' => 'example.test/acme',
        'registration_relocation_state' => 'reserved',
        'registration_authoritative_path' => $source,
        'status' => 'reserved',
    ]);
    $manager = orb105_registration_manager(new Orb105LocalSshExecutor);

    return
        compact(
            'manager',
            'node',
            'instance',
            'sourceRoot',
            'destinationRoot',
            'source',
            'destination',
        )
        + [
            'source_root' => $sourceRoot,
            'destination_root' => $destinationRoot,
        ];
}

function orb105_registration_manager(
    SshExecutor $executor,
    ?string $managedGroup = null,
): RemoteRegistrationSourceManager {
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/dev/null';
        }

        public function publicKey(): string
        {
            return 'unused';
        }
    };
    $knownHosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/dev/null';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    $accounts = new class($managedGroup) implements ManagedUserAccountResolver
    {
        public function __construct(
            private readonly ?string $managedGroup,
        ) {}

        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount($node->user, $this->managedGroup ?? $node->user, '/tmp');
        }
    };
    $manager = new RemoteRegistrationSourceManager(
        new AppDevSshExecutor($executor, $keys, $knownHosts),
        $accounts,
    );

    return $manager;
}

/** @param array{source_root: string, destination_root: string} $fixture */
function orb105_remove_relocation_fixture(array $fixture): void
{
    $files = new Filesystem;
    $files->deleteDirectory($fixture['source_root']);
    $files->deleteDirectory($fixture['destination_root']);
}

function orb105_copy_tree(string $source, string $destination): void
{
    $result = orb105_run(['cp', '-a', '--', $source, $destination]);
    expect($result->succeeded())->toBeTrue($result->stderr);
}

/** @param array{destination: string, instance: AppInstance} $fixture */
function orb105_relocation_scope(array $fixture): string
{
    $paths = glob(dirname($fixture['destination']).'/.orbit-registration-'.$fixture['instance']->id.'-*') ?: [];
    expect($paths)->toHaveCount(1);

    return $paths[0];
}

/** @return array<string, mixed> */
function orb105_relocation_state(string $scope): array
{
    $journal = file_get_contents($scope.'/state.journal');
    $records = [];
    foreach ([0, 16_384] as $offset) {
        $frame = substr($journal, $offset, 16_384);
        $length = unpack('Nlength', substr($frame, 0, 4))['length'];
        if ($length < 1 || $length > 16_348) {
            continue;
        }
        $data = substr($frame, 36, $length);
        if (hash('sha256', $data, true) === substr($frame, 4, 32)) {
            $record = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
            $records[$record['revision']] = $record['state'];
        }
    }
    expect($records)->not->toBeEmpty();
    krsort($records);

    return reset($records);
}

/** @return array<string, string> */
function orb105_git_state(string $path): array
{
    return [
        'head' => trim(orb105_git($path, ['rev-parse', 'HEAD'])->stdout),
        'branch' => trim(orb105_git($path, ['symbolic-ref', '-q', 'HEAD'])->stdout),
        'index' => hash_file('sha256', $path.'/.git/index'),
        'status' => orb105_git($path, ['status', '--porcelain=v2', '--untracked-files=all'])->stdout,
        'config' => orb105_git($path, ['config', '--local', '--null', '--list'])->stdout,
        'refs' => orb105_git($path, ['show-ref', '--head'])->stdout,
    ];
}

/** @param non-empty-list<string> $arguments */
function orb105_git(string $path, array $arguments): CommandResult
{
    return orb105_run(['env', 'GIT_OPTIONAL_LOCKS=0', 'git', '-C', $path, ...$arguments]);
}

/** @return list<array<string, int|string|null>> */
function orb105_complete_manifest(string $path): array
{
    $script = <<<'PYTHON'
        import hashlib, json, os, pathlib, stat, sys
        root = pathlib.Path(sys.argv[1])
        rows = []
        for entry in [root, *sorted(root.rglob('*'))]:
            info = entry.lstat()
            relative = '.' if entry == root else entry.relative_to(root).as_posix()
            kind = 'link' if entry.is_symlink() else 'file' if entry.is_file() else 'directory'
            rows.append({
                'path': relative,
                'type': kind,
                'mode': stat.S_IMODE(info.st_mode),
                'uid': info.st_uid,
                'gid': info.st_gid,
                'size': info.st_size if kind != 'directory' else None,
                'mtime_ns': info.st_mtime_ns,
                'content': hashlib.sha256(entry.read_bytes()).hexdigest() if kind == 'file' else None,
                'target': os.readlink(entry) if kind == 'link' else None,
            })
        print(json.dumps(rows, sort_keys=True, separators=(',', ':')))
        PYTHON;
    $result = orb105_run(['python3', '-c', $script, $path]);
    expect($result->succeeded())->toBeTrue($result->stderr);

    return json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<array<string, int|string|null>> */
function orb105_preserved_manifest(string $path): array
{
    return array_values(array_filter(
        orb105_complete_manifest($path),
        static fn (array $entry): bool => $entry['path'] !== '.git'
        && ! str_starts_with((string) $entry['path'], '.git/'),
    ));
}

/** @param non-empty-list<string> $arguments */
function orb105_run(array $arguments): CommandResult
{
    return new NativeProcessRunner(maxOutputBytes: 16_777_216)->run(new ProcessInvocation($arguments));
}

final readonly class Orb105LocalSshExecutor implements SshExecutor
{
    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        return new NativeProcessRunner()->run(new ProcessInvocation(
            arguments: $command->arguments,
            input: $command->input,
            protectedInput: $command->protectedInput,
        ));
    }
}

final class Orb105InterruptAfterPrepareSshExecutor implements SshExecutor
{
    private bool $interrupted = false;

    public function __construct(private readonly string $operation = 'prepare') {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $result = new Orb105LocalSshExecutor()->execute($connection, $command);

        if (! $this->interrupted && ($command->arguments[3] ?? null) === $this->operation && $result->succeeded()) {
            $this->interrupted = true;

            return new CommandResult(
                exitCode: 137,
                stdout: $result->stdout,
                stderr: 'Simulated process stop after remote prepare completed.',
                durationMs: $result->durationMs,
                truncated: $result->truncated,
            );
        }

        return $result;
    }
}

final readonly class Orb105InterruptStageSshExecutor implements SshExecutor
{
    public function __construct(private string $checkpoint) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        if (($command->arguments[3] ?? null) !== 'prepare') {
            return new Orb105LocalSshExecutor()->execute($connection, $command);
        }

        $hook = $this->checkpoint === 'incomplete-stage'
            ? <<<'PYTHON'
                import os, sys
                def interrupt_copy(event, arguments):
                    if event == 'shutil.copyfile' and str(arguments[1]).startswith('/proc/self/fd/'):
                        os._exit(137)
                sys.addaudithook(interrupt_copy)
                PYTHON
            : <<<'PYTHON'
                import os, sys
                def interrupt_verified_stage(frame, event, result):
                    if event == 'return' and frame.f_code.co_name == 'verify' and str(frame.f_locals.get('path','')).endswith('/stage'):
                        os._exit(137)
                    return interrupt_verified_stage
                sys.settrace(interrupt_verified_stage)
                PYTHON;
        $arguments = $command->arguments;
        $arguments[2] = $hook."\n".$arguments[2];

        return new NativeProcessRunner()->run(new ProcessInvocation($arguments));
    }
}

final readonly class Orb105NativeHookSshExecutor implements SshExecutor
{
    public function __construct(private string $operation, private string $hook) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        if (($command->arguments[3] ?? null) !== $this->operation) {
            return new Orb105LocalSshExecutor()->execute($connection, $command);
        }

        $arguments = $command->arguments;
        $arguments[2] = $this->hook."\n".$arguments[2];

        return new NativeProcessRunner()->run(new ProcessInvocation($arguments));
    }
}

final readonly class Orb105InitializeCallbackSshExecutor implements SshExecutor
{
    /** @param Closure(CommandResult): CommandResult $callback */
    public function __construct(private Closure $callback) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $result = new Orb105LocalSshExecutor()->execute($connection, $command);

        return ($command->arguments[3] ?? null) === 'initialize' && $result->succeeded()
            ? ($this->callback)($result)
            : $result;
    }
}

final class Orb105InterruptPartialPrepareSshExecutor implements SshExecutor
{
    private bool $interrupted = false;

    public function __construct(
        private readonly string $movedSource,
        private readonly string $unmovedSource,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        if ($this->interrupted || ($command->arguments[3] ?? null) !== 'prepare') {
            return new Orb105LocalSshExecutor()->execute($connection, $command);
        }

        $this->interrupted = true;
        $process = new SymfonyProcess($command->arguments);
        $process->start();
        $deadline = microtime(true) + 30;

        while ($process->isRunning() && microtime(true) < $deadline) {
            if (! file_exists($this->movedSource) && is_dir($this->unmovedSource)) {
                $process->stop(0, SIGKILL);

                return new CommandResult(
                    exitCode: $process->getExitCode() ?? 137,
                    stdout: $process->getOutput(),
                    stderr: $process->getErrorOutput(),
                    durationMs: 0,
                    truncated: false,
                );
            }
        }

        if ($process->isRunning()) {
            $process->stop(0, SIGKILL);
        }

        return new CommandResult(
            exitCode: $process->getExitCode() ?? 1,
            stdout: $process->getOutput(),
            stderr: 'Partial prepare interruption was not observed.',
            durationMs: 0,
            truncated: false,
        );
    }
}

final class Orb105InterruptingCleanupSshExecutor implements SshExecutor
{
    private bool $interrupted = false;

    public function __construct(
        private readonly string $source,
        private readonly string $trigger,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        if ($this->interrupted || ($command->arguments[3] ?? null) !== 'cleanup') {
            return new Orb105LocalSshExecutor()->execute($connection, $command);
        }

        $this->interrupted = true;
        $process = new SymfonyProcess($command->arguments);
        $process->start();
        $deadline = microtime(true) + 30;

        while ($process->isRunning() && microtime(true) < $deadline) {
            if (! file_exists($this->trigger) && is_dir($this->source)) {
                $process->stop(0, SIGKILL);

                return new CommandResult(
                    exitCode: $process->getExitCode() ?? 137,
                    stdout: $process->getOutput(),
                    stderr: $process->getErrorOutput(),
                    durationMs: 0,
                    truncated: false,
                );
            }
        }

        if ($process->isRunning()) {
            $process->stop(0, SIGKILL);
        }

        return new CommandResult(
            exitCode: $process->getExitCode() ?? 1,
            stdout: $process->getOutput(),
            stderr: 'Cleanup interruption was not observed.',
            durationMs: 0,
            truncated: false,
        );
    }
}
