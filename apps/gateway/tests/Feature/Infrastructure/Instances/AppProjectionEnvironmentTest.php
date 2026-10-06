<?php

declare(strict_types=1);

use App\Domain\Instances\Environment\AppProjectionEnvironmentTarget;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\InstanceTestEnvironment;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Instances\AppProjectionEnvironmentProgram;
use App\Infrastructure\Instances\NativeAppProjectionEnvironment;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\DatabaseConnection;
use App\Models\Instance;
use App\Models\InstanceAppProjection;
use App\Models\InstanceAppProjectionStep;
use App\Models\InstanceAppUpdate;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\LocalAppProjectionEnvironmentTransport;

describe('app projection native environment', function (): void {
    it('stages only the candidate env and managed testing paths with protected receipts and no old-path deletion', function (): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run): void {
            $before = file_get_contents($root.'/checkout/old/.env');
            $result = $run('prepare');
            foreach (['stable', 'release'] as $scope) {
                $prefix = $scope === 'stable' ? '/checkout' : '/release-a';
                foreach (['.env', '.env.testing'] as $name) {
                    expect(file_get_contents($root.$prefix.'/new/'.$name))->toBe(file_get_contents($root.$prefix.'/old/'.$name))
                        ->and(fileperms($root.$prefix.'/new/'.$name) & 0777)->toBe(0600)
                        ->and(fileowner($root.$prefix.'/new/'.$name))->toBe(posix_geteuid());
                }
                expect(fileperms($root.$prefix.'/old/.env') & 0777)->toBe(0640);
            }
            expect(file_get_contents($root.'/checkout/old/.env'))->toBe($before)
                ->and(file_get_contents($root.'/checkout/sibling/.env'))->toBe('sibling-only-secret')
                ->and(file_get_contents($root.'/published.json'))->toBe('{"web":"old","worker":"sibling"}')
                ->and(file_exists($root.'/checkout/.env'))->toBeFalse()
                ->and($result->getOutput())->not->toContain('private-app-key', 'sibling-only-secret');
            $manifest = $root.'/receipts/'.$payload['binding']['receipt_id'].'/manifest.json';
            expect(fileperms($manifest) & 0777)->toBe(0600)->and(fileperms(dirname($manifest)) & 0777)->toBe(0700);
            expect($run('recover')->getOutput())->toBe($result->getOutput());
        });
    });

    it('snapshots matching destinations even when their protections differ', function (): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run): void {
            copy($root.'/checkout/old/.env', $root.'/checkout/new/.env');
            chmod($root.'/checkout/new/.env', 0640);
            $run('prepare');
            expect(fileperms($root.'/checkout/new/.env') & 0777)->toBe(0600);
            $receipt = json_decode($run('recover')->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            expect($receipt['snapshots'])->toHaveCount(1);
            $run('restore');
            clearstatcache();
            expect(fileperms($root.'/checkout/new/.env') & 0777)->toBe(0640)
                ->and(fileowner($root.'/checkout/new/.env'))->toBe(posix_geteuid())
                ->and(filegroup($root.'/checkout/new/.env'))->toBe(posix_getegid());
        });
    });

    it('leaves empty unconfigured apps file-free without importing siblings', function (): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run): void {
            foreach (['checkout', 'release-a'] as $checkout) {
                unlink($root.'/'.$checkout.'/old/.env');
                unlink($root.'/'.$checkout.'/old/.env.testing');
            }
            $run('prepare', true, ['files' => []]);
            $run('recover');
            $run('restore');
            expect(file_exists($root.'/checkout/new/.env'))->toBeFalse()
                ->and(file_exists($root.'/release-a/new/.env.testing'))->toBeFalse()
                ->and(file_get_contents($root.'/checkout/sibling/.env'))->toBe('sibling-only-secret');
        });
    });

    it('refuses foreign additions to recorded absent paths before initial completion', function (bool $configured, string $side): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run) use ($configured, $side): void {
            $files = projection_environment_absent_files($root, $payload, $configured);
            $name = $configured ? '.env.testing' : '.env';
            $path = $root.'/release-a/'.$side.'/'.$name;
            $hook = "        manifest['complete'], manifest['state'] = True, 'prepared'";
            $injection = projection_environment_foreign_addition($path, 8);
            $script = str_replace($hook, $injection."\n".$hook, AppProjectionEnvironmentProgram::script());
            expect($run('prepare', false, ['files' => $files], $script)->getExitCode())->toBe(43)
                ->and(file_get_contents($path))->toBe('foreign-addition-secret')
                ->and(file_get_contents($root.'/checkout/sibling/.env'))->toBe('sibling-only-secret');
        });
    })->with([[false, 'old'], [false, 'new'], [true, 'old'], [true, 'new']]);

    it('refuses unsafe checkout and intermediate parents with safe app leaves before writing', function (string $boundary, int $mode): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run) use ($boundary, $mode): void {
            $extra = projection_environment_nested_targets($root, $payload);
            $parent = $root.'/checkout'.($boundary === 'checkout' ? '' : '/parent');
            chmod($parent, $mode);
            expect(fileperms($root.'/checkout/parent/old') & 0777)->toBe(0755)
                ->and(fileperms($root.'/checkout/parent/new') & 0777)->toBe(0755);
            expect($run('prepare', false, $extra)->getExitCode())->toBe(42)
                ->and(file_exists($root.'/checkout/parent/new/.env'))->toBeFalse()
                ->and(file_exists($root.'/release-a/new/.env'))->toBeFalse()
                ->and(file_get_contents($root.'/checkout/parent/old/.env'))->toContain('private-app-key');
        });
    })->with([['checkout', 0775], ['checkout', 0777], ['intermediate', 0775], ['intermediate', 0777]]);

    it('refuses foreign-owned checkout and intermediate metadata without relying on unsafe leaf ownership', function (string $boundary): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run) use ($boundary): void {
            $extra = projection_environment_nested_targets($root, $payload);
            $parent = $root.'/checkout'.($boundary === 'checkout' ? '' : '/parent');
            $encoded = json_encode($parent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            // Only one real parent's observed UID changes: this worker cannot chown a fixture to another user.
            $hook = '        meta = os.fstat(fd)';
            $injection = $hook."\n        if current_path == {$encoded}:\n            metadata = list(meta); metadata[4] = 65534; meta = os.stat_result(metadata)";
            $script = str_replace($hook, $injection, AppProjectionEnvironmentProgram::script());
            expect($run('prepare', false, $extra, $script)->getExitCode())->toBe(42)
                ->and(file_exists($root.'/checkout/parent/new/.env'))->toBeFalse()
                ->and(file_exists($root.'/release-a/new/.env'))->toBeFalse();
        });
    })->with(['checkout', 'intermediate']);

    it('rejects source and destination conflicts before any allowed target changes', function (string $relative): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run) use ($relative): void {
            file_put_contents($root.'/'.$relative, 'unsynchronized-secret');
            expect($run('prepare', false)->getExitCode())->toBe(42)
                ->and(file_get_contents($root.'/'.$relative))->toBe('unsynchronized-secret')
                ->and(file_exists($root.'/checkout/new/.env'))->toBeFalse();
        });
    })->with(['checkout/old/.env', 'checkout/old/.env.testing', 'release-a/new/.env', 'release-a/new/.env.testing']);

    it('rejects file symlinks and hardlinks without reading or replacing the linked secret', function (string $relative, bool $hard): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run) use ($relative, $hard): void {
            $path = $root.'/'.$relative;
            if (file_exists($path)) {
                unlink($path);
            }
            if ($hard) {
                link($root.'/checkout/sibling/.env', $path);
            } else {
                symlink($root.'/checkout/sibling/.env', $path);
            }
            $result = $run('prepare', false);
            expect($result->isSuccessful())->toBeFalse()->and($result->getOutput().$result->getErrorOutput())->not->toContain('sibling-only-secret')
                ->and(file_get_contents($root.'/checkout/sibling/.env'))->toBe('sibling-only-secret')
                ->and(file_exists($root.'/release-a/new/.env'))->toBeFalse();
        });
    })->with([['checkout/old/.env', false], ['checkout/new/.env', false], ['checkout/new/.env.testing', true]]);

    it('refuses traversal symlinked parents writable directories source overlap and capacity exhaustion', function (string $fault): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run) use ($fault): void {
            $extra = [];
            $script = null;
            if ($fault === 'parent-link') {
                rmdir($root.'/checkout/new');
                symlink($root.'/checkout/sibling', $root.'/checkout/new');
            } elseif ($fault === 'writable') {
                chmod($root.'/checkout/new', 0777);
            } elseif ($fault === 'capacity') {
                $script = str_replace('fs.f_bavail * fs.f_frsize', '0', AppProjectionEnvironmentProgram::script());
            } else {
                $contexts = $payload['contexts'];
                $contexts[0]['candidate'] = $fault === 'overlap' ? $contexts[1]['old'] : $root.'/checkout/../outside';
                $identities = $payload['targets'];
                $identities['stable.candidate'] = $contexts[0]['candidate'];
                $extra = ['contexts' => $contexts, 'targets' => $identities];
            }
            expect($run('prepare', false, $extra, $script)->isSuccessful())->toBeFalse()
                ->and(file_get_contents($root.'/checkout/sibling/.env'))->toBe('sibling-only-secret')
                ->and(file_get_contents($root.'/release-a/old/.env'))->toContain('private-app-key')
                ->and(file_exists($root.'/release-a/new/.env'))->toBeFalse();
        });
    })->with(['parent-link', 'writable', 'capacity', 'traversal', 'overlap']);
});

describe('app projection environment recovery', function (): void {
    it('resumes from durable inode evidence before and after the replacement without recapturing snapshots', function (bool $after): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run) use ($after): void {
            copy($root.'/checkout/old/.env', $root.'/checkout/new/.env');
            chmod($root.'/checkout/new/.env', 0640);
            $statement = "os.replace(record['pending'], record['name'], src_dir_fd=fd, dst_dir_fd=fd); sync(fd)";
            $script = str_replace($statement, $after ? $statement.'; os._exit(99)' : 'os._exit(99); '.$statement, AppProjectionEnvironmentProgram::script());
            expect($run('prepare', false, [], $script)->getExitCode())->toBe(99);
            $manifestPath = $root.'/receipts/'.$payload['binding']['receipt_id'].'/manifest.json';
            $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
            $snapshot = file_get_contents(dirname($manifestPath).'/before-0');
            $run('recover');
            expect(file_get_contents(dirname($manifestPath).'/before-0'))->toBe($snapshot);
            $recovered = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
            expect($recovered['records'][0]['before'])->toBe($manifest['records'][0]['before']);
            $run('restore');
            $run('restore-recover');
            clearstatcache();
            expect(fileperms($root.'/checkout/new/.env') & 0777)->toBe(0640)
                ->and(file_get_contents($root.'/checkout/new/.env'))->toBe($snapshot)
                ->and(file_exists($root.'/checkout/new/.env.testing'))->toBeFalse()
                ->and(file_exists($root.'/release-a/new/.env'))->toBeFalse();
        });
    })->with([false, true]);

    it('restores interrupted preparation and retries lost restore acknowledgments without deleting old environments', function (): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run): void {
            $statement = "os.replace(record['pending'], record['name'], src_dir_fd=fd, dst_dir_fd=fd); sync(fd)";
            $script = str_replace($statement, $statement.'; os._exit(99)', AppProjectionEnvironmentProgram::script());
            $run('prepare', false, [], $script);
            $run('restore');
            $run('restore-recover');
            foreach (['checkout', 'release-a'] as $checkout) {
                expect(file_exists($root.'/'.$checkout.'/new/.env'))->toBeFalse()
                    ->and(file_get_contents($root.'/'.$checkout.'/old/.env'))->toContain('private-app-key');
            }
        });
    });

    it('refuses foreign replacements even when the bytes and mode match the owned result', function (string $action): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run) use ($action): void {
            $run('prepare');
            $path = $root.'/release-a/new/.env';
            $contents = file_get_contents($path);
            rename($path, $path.'.foreign-inode');
            file_put_contents($path, $contents);
            chmod($path, 0600);
            expect($run($action, false)->getExitCode())->toBe(43)
                ->and(file_get_contents($path))->toBe($contents)
                ->and(file_exists($root.'/checkout/new/.env'))->toBeTrue();
        });
    })->with(['recover', 'restore', 'cleanup']);

    it('records a definite preflight rejection so rollback can clean its proven snapshots without recapture', function (): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run): void {
            file_put_contents($root.'/release-a/old/.env', 'unsynchronized-secret');
            expect($run('prepare', false)->getExitCode())->toBe(42);
            $directory = $root.'/receipts/'.$payload['binding']['receipt_id'];
            $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            expect($manifest['state'])->toBe('rejected')->and($manifest['reason'])->toBe('source');
            expect($run('recover', false)->isSuccessful())->toBeFalse();
            $run('restore');
            $run('restore-recover');
            expect(glob($directory.'/candidate-*'))->toBe([])
                ->and(file_get_contents($root.'/release-a/old/.env'))->toBe('unsynchronized-secret')
                ->and(file_exists($root.'/checkout/new/.env'))->toBeFalse();
        });
    });

    it('refuses replacements introduced after set validation or while restoring an earlier target', function (bool $existing, string $timing): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run) use ($existing, $timing): void {
            $victim = $root.'/checkout/new/.env.testing';
            if ($existing) {
                copy($root.'/checkout/old/.env.testing', $victim);
                chmod($victim, 0640);
            }
            $run('prepare');
            $script = AppProjectionEnvironmentProgram::script();
            if ($timing === 'after-validation') {
                $hook = "\n        for index, record in enumerate(records):\n";
                $position = strrpos($script, $hook);
                expect($position)->not->toBeFalse();
                $script = substr_replace($script, "\n".projection_environment_foreign_replacement($victim, 8), $position, 0);
            } else {
                $hook = "                record['restored'] = True";
                $injection = $hook."\n                if index == 0:\n".projection_environment_foreign_replacement($victim, 20);
                $script = str_replace($hook, $injection, $script);
            }
            $result = $run('restore', false, [], $script);
            expect($result->getExitCode())->toBe(43)
                ->and($result->getOutput())->toContain('foreign_replacement')
                ->and(file_get_contents($victim))->toBe('foreign-replacement-secret')
                ->and(file_get_contents($root.'/checkout/old/.env.testing'))->toContain('private-app-key');
        });
    })->with([[false, 'after-validation'], [true, 'after-validation'], [false, 'earlier-target'], [true, 'earlier-target']]);

    it('rechecks snapshot identity at restore copy time after set-wide validation', function (): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run): void {
            $path = $root.'/checkout/new/.env';
            copy($root.'/checkout/old/.env', $path);
            chmod($path, 0640);
            $run('prepare');
            $snapshot = $root.'/receipts/'.$payload['binding']['receipt_id'].'/before-0';
            $script = AppProjectionEnvironmentProgram::script();
            $hook = "\n        for index, record in enumerate(records):\n";
            $position = strrpos($script, $hook);
            expect($position)->not->toBeFalse();
            $script = substr_replace($script, "\n".projection_environment_foreign_addition($snapshot, 8), $position, 0);
            $result = $run('restore', false, [], $script);
            expect($result->getExitCode())->toBe(43)
                ->and(file_get_contents($path))->toBe(file_get_contents($root.'/checkout/old/.env'))
                ->and($result->getOutput().$result->getErrorOutput())->not->toContain('private-app-key', 'foreign-addition-secret');
        });
    });

    it('refuses newly appearing absent env and unmanaged testing files on retry and cleanup', function (bool $configured, string $action, string $side): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run) use ($configured, $action, $side): void {
            $files = projection_environment_absent_files($root, $payload, $configured);
            $run('prepare', true, ['files' => $files]);
            $manifestPath = $root.'/receipts/'.$payload['binding']['receipt_id'].'/manifest.json';
            $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
            expect($manifest['records'])->toHaveCount(4);
            if ($action === 'cleanup-recover') {
                $run('cleanup');
            }
            $name = $configured ? '.env.testing' : '.env';
            $path = $root.'/release-a/'.$side.'/'.$name;
            file_put_contents($path, 'foreign-addition-secret');
            chmod($path, 0600);
            expect($run($action, false)->getExitCode())->toBe(43)
                ->and(file_get_contents($path))->toBe('foreign-addition-secret')
                ->and(file_get_contents($root.'/checkout/sibling/.env'))->toBe('sibling-only-secret');
        });
    })->with([false, true])->with(['recover', 'cleanup', 'cleanup-recover'])->with(['old', 'new']);

    it('refuses moved directories for file-free and unmanaged testing contexts without deleting foreign additions', function (bool $configured, string $action, string $side): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run) use ($configured, $action, $side): void {
            $files = projection_environment_absent_files($root, $payload, $configured);
            $run('prepare', true, ['files' => $files]);
            $directory = $root.'/release-a/'.$side;
            rename($directory, $directory.'-moved');
            mkdir($directory, 0755);
            $name = $configured ? '.env.testing' : '.env';
            file_put_contents($directory.'/'.$name, 'foreign-directory-secret');
            chmod($directory.'/'.$name, 0600);
            expect($run($action, false)->getExitCode())->toBe(43)
                ->and(file_get_contents($directory.'/'.$name))->toBe('foreign-directory-secret');
        });
    })->with([false, true])->with(['recover', 'cleanup'])->with(['old', 'new']);

    it('reverifies recorded checkout and intermediate access protections before recovery operations', function (string $boundary, string $action): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run) use ($boundary, $action): void {
            $extra = projection_environment_nested_targets($root, $payload);
            $run('prepare', true, $extra);
            $parent = $root.'/checkout'.($boundary === 'checkout' ? '' : '/parent');
            chmod($parent, 0775);
            expect($run($action, false, $extra)->getExitCode())->toBe(43)
                ->and(file_get_contents($root.'/checkout/parent/new/.env'))->toContain('private-app-key')
                ->and(file_get_contents($root.'/checkout/parent/old/.env'))->toContain('private-app-key');
        });
    })->with(['checkout', 'intermediate'])->with(['recover', 'restore', 'cleanup']);

    it('refuses replaced parent identities even when the safe app leaves and files retain their inodes', function (string $boundary, string $action): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run) use ($boundary, $action): void {
            $extra = projection_environment_nested_targets($root, $payload);
            $run('prepare', true, $extra);
            $parent = $root.'/checkout'.($boundary === 'checkout' ? '' : '/parent');
            $inode = fileinode($root.'/checkout/parent/new/.env');
            rename($parent, $parent.'-held');
            mkdir($parent, 0755);
            foreach (array_diff(scandir($parent.'-held'), ['.', '..']) as $entry) {
                rename($parent.'-held/'.$entry, $parent.'/'.$entry);
            }
            clearstatcache();
            expect(fileinode($root.'/checkout/parent/new/.env'))->toBe($inode);
            expect($run($action, false, $extra)->getExitCode())->toBe(43)
                ->and(file_get_contents($root.'/checkout/parent/new/.env'))->toContain('private-app-key');
        });
    })->with(['checkout', 'intermediate'])->with(['recover', 'restore', 'cleanup']);

    it('requires intact protected evidence and exact binding before ambiguous retry', function (string $fault): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run) use ($fault): void {
            $run('prepare');
            $directory = $root.'/receipts/'.$payload['binding']['receipt_id'];
            $extra = [];
            if ($fault === 'snapshot') {
                file_put_contents($directory.'/candidate-0', 'foreign-secret');
            } elseif ($fault === 'mode') {
                chmod($directory.'/manifest.json', 0644);
            } elseif ($fault === 'missing') {
                unlink($directory.'/manifest.json');
            } else {
                $binding = $payload['binding'];
                $binding['intent_digest'] = str_repeat('b', 64);
                $extra['binding'] = $binding;
            }
            $before = file_get_contents($root.'/checkout/new/.env');
            $result = $run('recover', false, $extra);
            expect($result->getExitCode())->toBe(43)
                ->and(file_get_contents($root.'/checkout/new/.env'))->toBe($before)
                ->and($result->getOutput().$result->getErrorOutput())->not->toContain('foreign-secret', 'private-app-key');
        });
    })->with(['snapshot', 'mode', 'missing', 'binding']);

    it('fails closed when a committed intent has no receipt instead of capturing an already modified destination', function (): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run): void {
            expect($run('recover', false)->getExitCode())->toBe(43)
                ->and($run('prepare', false)->getExitCode())->toBe(43)
                ->and(file_exists($root.'/checkout/new/.env'))->toBeFalse();
        });
    });

    it('cleans only owned protected snapshots after publication and checkpoints an interrupted unlink', function (): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run): void {
            $run('prepare');
            $statement = 'os.unlink(name, dir_fd=origin); sync(origin)';
            $script = str_replace($statement, $statement.'; os._exit(99)', AppProjectionEnvironmentProgram::script());
            expect($run('cleanup', false, [], $script)->getExitCode())->toBe(99);
            $first = $run('cleanup-recover');
            expect($run('cleanup-recover')->getOutput())->toBe($first->getOutput());
            expect(glob($root.'/receipts/'.$payload['binding']['receipt_id'].'/candidate-*'))->toBe([])
                ->and(file_get_contents($root.'/checkout/new/.env'))->toContain('private-app-key')
                ->and(file_get_contents($root.'/checkout/old/.env'))->toContain('private-app-key')
                ->and(file_get_contents($root.'/checkout/sibling/.env'))->toBe('sibling-only-secret');
        });
    });

    it('restores exact access attributes after an interrupted existing-file restore', function (): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run): void {
            $path = $root.'/checkout/new/.env';
            copy($root.'/checkout/old/.env', $path);
            chmod($path, 0640);
            (new Process(['setfacl', '-m', 'u:65534:r--', $path]))->mustRun();
            $before = (new Process(['getfacl', '-cp', $path]))->mustRun()->getOutput();
            $run('prepare');
            $statement = "os.replace(restore_name, record['name'], src_dir_fd=fd, dst_dir_fd=fd); sync(fd)";
            $script = str_replace($statement, $statement.'; os._exit(99)', AppProjectionEnvironmentProgram::script());
            expect($run('restore', false, [], $script)->getExitCode())->toBe(99);
            $run('restore-recover');
            expect((new Process(['getfacl', '-cp', $path]))->mustRun()->getOutput())->toBe($before)
                ->and(fileowner($path))->toBe(posix_geteuid())
                ->and(filegroup($path))->toBe(posix_getegid());
        });
    });

    it('refuses an unproven pending artifact after interruption before its identity checkpoint', function (): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run): void {
            $statement = "record['result'] = create(fd, record['pending'], contents, uid, gid)";
            $script = str_replace($statement, $statement.'; os._exit(99)', AppProjectionEnvironmentProgram::script());
            expect($run('prepare', false, [], $script)->getExitCode())->toBe(99);
            expect($run('recover', false)->isSuccessful())->toBeFalse()
                ->and($run('restore', false)->getExitCode())->toBe(43)
                ->and(file_exists($root.'/checkout/new/.env'))->toBeFalse();
            expect(glob($root.'/checkout/new/.orbit-env-*'))->toHaveCount(1);
        });
    });

    it('refuses a missing owned existing destination rather than restoring over foreign deletion', function (): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run): void {
            copy($root.'/checkout/old/.env', $root.'/checkout/new/.env');
            chmod($root.'/checkout/new/.env', 0640);
            $run('prepare');
            unlink($root.'/checkout/new/.env');
            expect($run('restore', false)->getExitCode())->toBe(43)
                ->and(file_exists($root.'/checkout/new/.env'))->toBeFalse()
                ->and(file_exists($root.'/release-a/new/.env'))->toBeTrue();
        });
    });

    it('refuses moved target directories without restoring or deleting the foreign path', function (): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run): void {
            $run('prepare');
            rename($root.'/checkout/new', $root.'/checkout/moved');
            mkdir($root.'/checkout/new');
            file_put_contents($root.'/checkout/new/.env', 'foreign-directory-secret');
            expect($run('restore', false)->getExitCode())->toBe(43)
                ->and(file_get_contents($root.'/checkout/new/.env'))->toBe('foreign-directory-secret')
                ->and(file_get_contents($root.'/checkout/moved/.env'))->toContain('private-app-key');
        });
    });

    it('treats stable home and selected release as independent artifact identities', function (): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run): void {
            $run('prepare');
            $path = $root.'/release-a/new/.env';
            expect(fileinode($path))->not->toBe(fileinode($root.'/checkout/new/.env'));
            file_put_contents($path, 'release-only-edit');
            expect(file_get_contents($root.'/checkout/new/.env'))->toContain('private-app-key')
                ->and($run('restore', false)->getExitCode())->toBe(43)
                ->and(file_exists($root.'/checkout/new/.env'))->toBeTrue();
        });
    });
});

describe('app projection native environment adapter', function (): void {
    it('uses encrypted app-keyed configuration and explicit contexts under the persisted owner without publishing maps', function (): void {
        projection_environment_fixture(function (string $root): void {
            [$instance, $adapter, $step, $transport] = native_projection_environment_fixture($root);
            $receipt = $adapter->mutate($step);
            expect($receipt->matches($step))->toBeTrue()
                ->and($instance->refresh()->appConfiguration('web')['path'])->toBe('old')
                ->and($instance->app_overrides)->toBe([])
                ->and($instance->project->fresh()->apps)->toContain(['name' => 'web', 'path' => 'old', 'web_root' => null, 'type' => 'node-package'])
                ->and(file_get_contents($root.'/checkout/new/.env'))->toBe("APP_KEY=\"private-app-key\"\nDB_DATABASE=\"app\"\n")
                ->and(file_get_contents($root.'/checkout/sibling/.env'))->toBe('sibling-only-secret')
                ->and(json_encode($step->fresh()->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain('private-app-key')
                ->and(DB::table('instance_environment_values')->where('app', 'web')->value('env_value'))->not->toContain('private-app-key');
            expect($adapter->recover($step)->evidence())->toBe($receipt->evidence())
                ->and($transport->lastInput)->not->toContain('private-app-key', 'sibling-only-secret');
            foreach ($transport->commands as $command) {
                expect(implode(' ', $command->arguments))->not->toContain('private-app-key', 'sibling-only-secret')
                    ->and($command->input)->toBeNull();
            }
            // Ordinary environment readers still refuse the persisted projection owner.
            expect(fn () => app(InstanceEnvironmentContextResolver::class)->resolve($instance, true, app: 'web'))
                ->toThrow(ResourceOperationException::class);
        });
    });

    it('renders managed testing configuration for the same app in both explicit filesystem scopes', function (): void {
        projection_environment_fixture(function (string $root): void {
            [$instance, $adapter, $step] = native_projection_environment_fixture($root);
            $database = DatabaseConnection::query()->create(['slug' => 'projection-database', 'driver' => 'mysql', 'owner_instance_id' => $instance->id,
                'node_id' => $instance->node_id, 'database' => 'app', 'test_database' => 'app_test']);
            $database->targets()->create(['instance_id' => $instance->id, 'prefix' => 'DB']);
            $context = app(InstanceEnvironmentContextResolver::class)->resolveForProjection($instance, $step->instance_app_projection_id, 'web');
            $values = app(InstanceEnvironmentStore::class)->projectionSnapshot($context, $step)->values();
            $testing = app(InstanceTestEnvironment::class)->values($instance->id, $values);
            $expected = app(InstanceEnvironmentRenderer::class)->render($context, $testing);
            foreach (['checkout', 'release-a'] as $checkout) {
                file_put_contents($root.'/'.$checkout.'/old/.env.testing', $expected);
                chmod($root.'/'.$checkout.'/old/.env.testing', 0640);
            }
            expect($adapter->mutate($step)->matches($step))->toBeTrue();
            foreach (['checkout', 'release-a'] as $checkout) {
                expect(file_get_contents($root.'/'.$checkout.'/new/.env.testing'))->toBe($expected)
                    ->and(fileperms($root.'/'.$checkout.'/new/.env.testing') & 0777)->toBe(0600);
            }
        });
    });

    it('keeps an empty encrypted app configuration file-free and refuses wrong remote ownership', function (): void {
        projection_environment_fixture(function (string $root, array $payload, Closure $run): void {
            [$instance, $adapter, $step] = native_projection_environment_fixture($root);
            InstanceEnvironmentValue::query()->where('instance_id', $instance->id)->where('app', 'web')->delete();
            unlink($root.'/checkout/old/.env');
            unlink($root.'/release-a/old/.env');
            expect($adapter->mutate($step)->matches($step))->toBeTrue()
                ->and(file_exists($root.'/checkout/new/.env'))->toBeFalse()
                ->and(file_get_contents($root.'/checkout/sibling/.env'))->toBe('sibling-only-secret');
        });
        projection_environment_fixture(function (string $root, array $payload, Closure $run): void {
            expect($run('prepare', false, ['user' => 'nobody'])->isSuccessful())->toBeFalse()
                ->and(file_exists($root.'/checkout/new/.env'))->toBeFalse();
        });
    });

    it('returns a reusable app-scoped redacted conflict instead of exposing remote output', function (): void {
        projection_environment_fixture(function (string $root): void {
            [, $adapter, $step] = native_projection_environment_fixture($root);
            file_put_contents($root.'/checkout/old/.env', 'unsynchronized-secret');
            try {
                $adapter->mutate($step);
                test()->fail('Expected source conflict.');
            } catch (ResourceOperationException $exception) {
                expect($exception->errorCode)->toBe('instance.app_environment_conflict')
                    ->and($exception->details['app'])->toBe('web')
                    ->and($exception->getMessage())->not->toContain('unsynchronized-secret', 'private-app-key');
            }
            expect(file_exists($root.'/checkout/new/.env'))->toBeFalse();
        });
    });
});

describe('app projection environment recovery adapter', function (): void {
    it('recovers a lost write acknowledgment and checkpoints restore under a separate committed intent', function (): void {
        projection_environment_fixture(function (string $root): void {
            [$instance, $adapter, $step, $transport] = native_projection_environment_fixture($root);
            $transport->loseAcknowledgment = true;
            expect(fn () => $adapter->mutate($step))->toThrow(ResourceOperationException::class);
            expect(file_get_contents($root.'/checkout/new/.env'))->toContain('private-app-key');
            $receipt = $adapter->recover($step);
            expect($receipt->matches($step))->toBeTrue();
            $restore = InstanceAppProjectionStep::query()->create(['id' => (string) Str::uuid(),
                'instance_app_projection_id' => $step->instance_app_projection_id, 'step_key' => 'env-restore', 'sequence' => 2,
                'plan_digest' => $step->plan_digest, 'receipt_id' => (string) Str::uuid(), 'status' => 'intended',
                'intent' => [...$step->intent, 'phase' => 'restore', 'action' => 'restore', 'targets' => [...$adapter->targetIdentities(), 'restores_step_id' => $step->id]]]);
            $restored = $adapter->mutate($restore);
            expect($restored->matches($restore))->toBeTrue()
                ->and($adapter->recover($restore)->evidence())->toBe($restored->evidence())
                ->and(file_exists($root.'/checkout/new/.env'))->toBeFalse()
                ->and(file_exists($root.'/release-a/new/.env'))->toBeFalse()
                ->and(file_get_contents($root.'/checkout/old/.env'))->toContain('private-app-key')
                ->and($instance->refresh()->appConfiguration('web')['path'])->toBe('old');
        });
    });
});

/** @return array{Instance, NativeAppProjectionEnvironment, InstanceAppProjectionStep, LocalAppProjectionEnvironmentTransport} */
function native_projection_environment_fixture(string $root): array
{
    foreach (['checkout', 'release-a'] as $checkout) {
        unlink($root.'/'.$checkout.'/old/.env.testing');
    }
    $node = Node::query()->create(['name' => 'environment-projection', 'user' => posix_getpwuid(posix_geteuid())['name'], 'status' => 'active',
        'platform' => 'linux', 'public_ssh_host' => '192.0.2.20', 'wireguard_ip' => '10.44.0.3']);
    $node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Environment projection', 'slug' => 'env-projection', 'repository_url' => 'git@example.test:projection.git',
        'apps' => [['name' => 'web', 'path' => 'old', 'web_root' => null, 'type' => 'node-package'], ['name' => 'worker', 'path' => 'sibling', 'web_root' => null, 'type' => 'node-package']]]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'environment' => 'development',
        'checkout_path' => $root.'/checkout', 'source_layout' => 'checkout', 'branch' => 'main', 'provisioning_step' => 'active', 'status' => 'active',
        'app_runtime' => ['web' => ['laravel' => false, 'php_version' => '8.5'], 'worker' => ['laravel' => false, 'php_version' => '8.5']]]);
    $contexts = app(InstanceEnvironmentContextResolver::class);
    $published = $contexts->resolve($instance, true, app: 'web');
    $targetContext = static fn (string $path): InstanceEnvironmentContext => new InstanceEnvironmentContext($published->instanceId, $published->projectId,
        $published->nodeId, $published->environment, $path, $published->executionUser, $published->laravel, $published->routeId, $published->routeDomain,
        $published->nodeStatus, $published->node, app: 'web');
    $targets = [new AppProjectionEnvironmentTarget('stable', $published, $targetContext($root.'/checkout/new'), $root.'/checkout', $root.'/checkout', 'stable-home'),
        new AppProjectionEnvironmentTarget('release', $targetContext($root.'/release-a/old'), $targetContext($root.'/release-a/new'), $root.'/release-a', $root.'/release-a', 'release-a')];
    foreach (['APP_KEY' => 'private-app-key', 'DB_DATABASE' => 'app'] as $key => $value) {
        InstanceEnvironmentValue::query()->create(['instance_id' => $instance->id, 'app' => 'web', 'env_key' => $key, 'env_value' => $value]);
    }
    InstanceEnvironmentValue::query()->create(['instance_id' => $instance->id, 'app' => 'worker', 'env_key' => 'APP_KEY', 'env_value' => 'sibling-only-secret']);
    $owner = InstanceAppUpdate::query()->create(['id' => (string) Str::uuid(), 'instance_id' => $instance->id, 'fingerprint' => str_repeat('a', 64),
        'request' => ['web' => ['path' => 'new']], 'previous_overrides' => [], 'phase' => 'preparing']);
    $projection = InstanceAppProjection::query()->create(['id' => (string) Str::uuid(), 'instance_id' => $instance->id, 'active_instance_id' => $instance->id,
        'node_id' => $node->id, 'instance_app_update_id' => $owner->id, 'plan' => [], 'plan_digest' => str_repeat('a', 64), 'render_side' => 'before']);
    $transport = new LocalAppProjectionEnvironmentTransport($root);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/unused/environment-key');
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/unused/environment-hosts');
    $adapter = new NativeAppProjectionEnvironment(new DevelopmentSshExecutor($transport, $keys, $hosts), app(InstanceEnvironmentStore::class),
        app(InstanceEnvironmentRenderer::class), app(InstanceTestEnvironment::class), $published, $targets);
    $intent = ['phase' => 'prepare', 'app' => 'web', 'resource' => 'environment', 'action' => 'stage', 'targets' => $adapter->targetIdentities(),
        'recovery_action' => 'restore', 'instance_id' => $instance->id, 'node_id' => $node->id, 'project_update_id' => null, 'instance_app_update_id' => $owner->id];
    $step = InstanceAppProjectionStep::query()->create(['id' => (string) Str::uuid(), 'instance_app_projection_id' => $projection->id, 'step_key' => 'environment',
        'sequence' => 1, 'plan_digest' => $projection->plan_digest, 'intent' => $intent, 'receipt_id' => (string) Str::uuid(), 'status' => 'intended']);

    return [$instance, $adapter, $step, $transport];
}

/** @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function projection_environment_nested_targets(string $root, array $payload): array
{
    mkdir($root.'/checkout/parent', 0755);
    rename($root.'/checkout/old', $root.'/checkout/parent/old');
    rename($root.'/checkout/new', $root.'/checkout/parent/new');
    $contexts = $payload['contexts'];
    $contexts[0]['old'] = $root.'/checkout/parent/old';
    $contexts[0]['candidate'] = $root.'/checkout/parent/new';
    $targets = $payload['targets'];
    $targets['stable.old'] = $contexts[0]['old'];
    $targets['stable.candidate'] = $contexts[0]['candidate'];

    return ['contexts' => $contexts, 'targets' => $targets];
}

/** @param array<string, mixed> $payload
 * @return array<string, array{old: string, candidate: string}>
 */
function projection_environment_absent_files(string $root, array $payload, bool $configured): array
{
    foreach (['checkout', 'release-a'] as $checkout) {
        unlink($root.'/'.$checkout.'/old/.env.testing');
        if (! $configured) {
            unlink($root.'/'.$checkout.'/old/.env');
        }
    }

    return array_filter($payload['files'], static fn (string $key): bool => $configured && str_ends_with($key, ':.env'), ARRAY_FILTER_USE_KEY);
}

function projection_environment_foreign_addition(string $path, int $indent): string
{
    $prefix = str_repeat(' ', $indent);
    $encoded = json_encode($path, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    return $prefix."with open({$encoded}, 'wb') as foreign: foreign.write(b'foreign-addition-secret')\n".$prefix."os.chmod({$encoded}, 0o600)";
}

function projection_environment_foreign_replacement(string $path, int $indent): string
{
    $prefix = str_repeat(' ', $indent);
    $encoded = json_encode($path, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    return $prefix."os.rename({$encoded}, {$encoded} + '.foreign-held')\n".$prefix."with open({$encoded}, 'wb') as foreign: foreign.write(b'foreign-replacement-secret')\n".$prefix."os.chmod({$encoded}, 0o600)";
}

/** Execute the fixed program unchanged except for its privileged receipt root. Fault scripts
 * terminate at real durable boundaries; they are not caller-selectable production hooks.
 */
function projection_environment_fixture(Closure $test): void
{
    $root = sys_get_temp_dir().'/orbit-app-env-'.Str::uuid();
    $filesystem = new Filesystem;
    foreach (['receipts', 'checkout/old', 'checkout/new', 'checkout/sibling', 'release-a/old', 'release-a/new'] as $directory) {
        $filesystem->ensureDirectoryExists($root.'/'.$directory);
    }
    chmod($root.'/receipts', 0700);
    file_put_contents($root.'/published.json', '{"web":"old","worker":"sibling"}');
    file_put_contents($root.'/checkout/sibling/.env', 'sibling-only-secret');
    $contents = ['.env' => "APP_KEY=\"private-app-key\"\nDB_DATABASE=\"app\"\n", '.env.testing' => "APP_KEY=\"private-app-key\"\nAPP_ENV=\"testing\"\nDB_DATABASE=\"app_test\"\n"];
    $binding = ['step_id' => (string) Str::uuid(), 'projection_id' => (string) Str::uuid(), 'receipt_id' => (string) Str::uuid(),
        'plan_digest' => str_repeat('a', 64), 'intent_digest' => str_repeat('c', 64), 'instance_id' => 1, 'app' => 'web', 'owner' => (string) Str::uuid()];
    $targets = $contexts = $files = [];
    foreach (['stable' => 'checkout', 'release' => 'release-a'] as $scope => $checkout) {
        $context = ['scope' => $scope, 'old' => $root.'/'.$checkout.'/old', 'candidate' => $root.'/'.$checkout.'/new',
            'old_checkout' => $root.'/'.$checkout, 'candidate_checkout' => $root.'/'.$checkout, 'release' => $checkout];
        $contexts[] = $context;
        foreach ($context as $key => $value) {
            if ($key !== 'scope') {
                $targets[$scope.'.'.$key] = $value;
            }
        }
        foreach ($contents as $name => $bytes) {
            file_put_contents($root.'/'.$checkout.'/old/'.$name, $bytes);
            chmod($root.'/'.$checkout.'/old/'.$name, 0640);
            $files[$scope.':'.$name] = ['old' => base64_encode($bytes), 'candidate' => base64_encode($bytes)];
        }
    }
    $payload = ['binding' => $binding, 'targets' => $targets, 'contexts' => $contexts, 'files' => $files, 'user' => posix_getpwuid(posix_geteuid())['name']];
    $operations = [];
    foreach (['restore', 'cleanup'] as $action) {
        $operations[$action] = [...$binding, 'step_id' => (string) Str::uuid(), 'receipt_id' => (string) Str::uuid(), 'intent_digest' => hash('sha256', $action)];
    }
    $run = static function (string $action, bool $mustSucceed = true, array $extra = [], ?string $script = null) use ($root, $payload, $operations): Process {
        $operation = str_replace('-recover', '', $action);
        $input = [...$payload, 'action' => $action];
        if (isset($operations[$operation])) {
            $input = [...$input, 'binding' => $operations[$operation], 'source_receipt' => $payload['binding']['receipt_id'], 'source_binding' => $payload['binding'],
                'action' => str_ends_with($action, '-recover') ? 'recover' : $action];
        }
        $program = str_replace('/var/lib/orbit/app-environments', $root.'/receipts', $script ?? AppProjectionEnvironmentProgram::script());
        $process = new Process(['python3', '-c', $program]);
        $process->setInput(json_encode([...$input, ...$extra], JSON_THROW_ON_ERROR));
        $process->run();
        if ($mustSucceed) {
            expect($process->getErrorOutput())->toBe('')->and($process->getExitCode())->toBe(0, $process->getOutput());
        }
        clearstatcache();

        return $process;
    };
    try {
        $test($root, $payload, $run);
    } finally {
        $filesystem->deleteDirectory($root);
    }
}
