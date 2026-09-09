<?php

declare(strict_types=1);

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContext;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContextResolver;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriter;
use App\Domain\AppInstances\Environment\AppInstanceOperationPreflight;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$gateway = '/home/orbit/orbit/apps/gateway';
require $gateway.'/vendor/autoload.php';
$application = require $gateway.'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

final readonly class Orb211ProofSshExecutor implements SshExecutor
{
    public function __construct(
        private SshExecutor $inner,
        private ?string $fault,
        private bool $loseAcknowledgement,
        private ?CommandResult $forcedResult,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        if ($this->forcedResult instanceof CommandResult) {
            return $this->forcedResult;
        }

        $arguments = $command->arguments;
        $programFlag = array_search('-c', $arguments, true);

        if (is_int($programFlag) && is_string($this->fault)) {
            $program = $arguments[$programFlag + 1] ?? '';
            $arguments[$programFlag + 1] = match ($this->fault) {
                'candidate-write' => str_replace(
                    'written = os.write(candidate, chunk[offset:])',
                    'written = (_ for _ in ()).throw(OSError())',
                    $program,
                ),
                'permission' => str_replace(
                    'os.fchmod(candidate, 0o600)',
                    '(_ for _ in ()).throw(OSError())',
                    $program,
                ),
                'rename' => str_replace(
                    'os.replace(candidate_name, ".env", src_dir_fd=current, dst_dir_fd=current)',
                    '(_ for _ in ()).throw(OSError())',
                    $program,
                ),
                'post-rename-sync' => str_replace(
                    'os.fsync(current)',
                    '(_ for _ in ()).throw(OSError())',
                    $program,
                ),
                default => throw new RuntimeException('Unknown ORB-211 fault.'),
            };
        }

        $result = $this->inner->execute(
            $connection,
            new RemoteCommand(
                arguments: $arguments,
                input: $command->input,
                protectedInput: $command->protectedInput,
                maxOutputBytes: $command->maxOutputBytes,
            ),
        );

        if (! $this->loseAcknowledgement) {
            return $result;
        }

        return new CommandResult(255, '', 'Connection closed.', $result->durationMs, false);
    }
}

$specifications = [
    'development' => [
        'node' => 'app-dev',
        'environment' => 'development',
        'path' => '/home/orbit/orb211-environment-dev',
        'user' => 'orbit',
        'hostname' => 'orb211-development.orbit',
    ],
    'production' => [
        'node' => 'app-prod',
        'environment' => 'production',
        'path' => '/home/orbit-app-orb211',
        'user' => 'orbit-app-orb211',
        'hostname' => 'orb211-production.orbit',
    ],
];

$command = $argv[1] ?? '';
$failureStage = 'dispatch';

$instance = static function (string $label) use ($specifications): AppInstance {
    if (! array_key_exists($label, $specifications)) {
        throw new RuntimeException('Unknown ORB-211 fixture label.');
    }

    return AppInstance::query()
        ->whereHas('app', static fn ($query) => $query->where('slug', "orb211-{$label}"))
        ->sole();
};

$context = static function (string $label) use ($application, $instance): AppInstanceEnvironmentContext {
    return $application->make(AppInstanceEnvironmentContextResolver::class)
        ->resolve($instance($label), requireActiveNode: true);
};

$decorateSsh = static function (
    ?string $fault = null,
    bool $loseAcknowledgement = false,
    ?CommandResult $forcedResult = null,
) use ($application): void {
    $application->instance(
        SshExecutor::class,
        new Orb211ProofSshExecutor(
            $application->make(SshExecutor::class),
            $fault,
            $loseAcknowledgement,
            $forcedResult,
        ),
    );
};

$readInput = static function (): string {
    $contents = stream_get_contents(STDIN);

    if (! is_string($contents)) {
        throw new RuntimeException('Unable to read protected ORB-211 fixture input.');
    }

    return $contents;
};

$assertWrite = static function (
    string $label,
    bool $expectedChanged,
) use ($application, $context, $readInput): void {
    $result = $application->make(AppInstanceEnvironmentWriter::class)
        ->write($context($label), $readInput());

    if (! $result->confirmed || $result->changed !== $expectedChanged) {
        throw new RuntimeException('Unexpected confirmed ORB-211 write result.');
    }
};

try {
    match ($command) {
        'setup' => (static function () use ($specifications, &$failureStage): void {
            DB::transaction(static function () use ($specifications, &$failureStage): void {
                $failureStage = 'cleanup-existing';
                $appIds = App::query()->whereIn('slug', ['orb211-development', 'orb211-production'])->pluck('id');
                AppInstance::query()->whereIn('app_id', $appIds)->update(['status' => 'reserved']);
                Route::query()->whereIn('app_id', $appIds)->delete();
                AppInstance::query()->whereIn('app_id', $appIds)->delete();
                App::query()->whereIn('id', $appIds)->delete();

                foreach ($specifications as $label => $specification) {
                    $failureStage = "seed-{$label}";
                    $node = Node::query()->where('name', $specification['node'])->sole();
                    $app = App::query()->create([
                        'name' => "ORB-211 {$label}",
                        'slug' => "orb211-{$label}",
                        'repository_url' => "https://example.test/orb211-{$label}.git",
                        'default_branch' => 'main',
                        'root' => 'public',
                    ]);
                    $owner = AppInstance::query()->create([
                        'app_id' => $app->id,
                        'node_id' => $node->id,
                        'name' => 'default',
                        'environment' => $specification['environment'],
                        'checkout_path' => $specification['path'],
                        'production_home' => $specification['environment'] === 'production'
                            ? $specification['path']
                            : null,
                        'production_user' => $specification['environment'] === 'production'
                            ? $specification['user']
                            : null,
                        'source_is_laravel' => false,
                        'provisioning_step' => 'active',
                        'status' => 'active',
                    ]);
                    $route = Route::query()->create([
                        'app_id' => $app->id,
                        'node_id' => $node->id,
                        'hostname' => $specification['hostname'],
                        'provenance' => 'explicit',
                        'publication' => 'private',
                        'status' => 'pending',
                    ]);
                    $route->targets()->create([
                        'app_instance_id' => $owner->id,
                        'position' => 0,
                    ]);
                    $route->update(['status' => 'active']);
                }
            });
        })(),
        'cleanup' => (static function () use (&$failureStage): void {
            DB::transaction(static function () use (&$failureStage): void {
                $appIds = App::query()->whereIn('slug', ['orb211-development', 'orb211-production'])->pluck('id');
                $failureStage = 'cleanup-status';
                AppInstance::query()->whereIn('app_id', $appIds)->update(['status' => 'reserved']);
                $failureStage = 'cleanup-routes';
                Route::query()->whereIn('app_id', $appIds)->delete();
                $failureStage = 'cleanup-instances';
                AppInstance::query()->whereIn('app_id', $appIds)->delete();
                $failureStage = 'cleanup-apps';
                App::query()->whereIn('id', $appIds)->delete();
            });
        })(),
        'assert-clean' => (static function (): void {
            if (App::query()->whereIn('slug', ['orb211-development', 'orb211-production'])->exists()) {
                throw new RuntimeException('ORB-211 Gateway fixtures remain.');
            }
        })(),
        'node-ip' => (static function () use ($argv): void {
            echo Node::query()->where('name', $argv[2] ?? '')->sole()->wireguard_ip, "\n";
        })(),
        'set-path' => (static function () use ($argv, $instance): void {
            $owner = $instance($argv[2] ?? '');
            $path = $argv[3] ?? '';
            $attributes = ['checkout_path' => $path];

            if ($owner->environment === 'production') {
                $attributes['production_home'] = $path;
            }

            $owner->update($attributes);
        })(),
        'set-node-ip' => (static function () use ($argv): void {
            Node::query()->where('name', $argv[2] ?? '')->sole()->update(['wireguard_ip' => $argv[3] ?? '']);
        })(),
        'describe' => (static function () use ($argv, $context, $instance, $specifications): void {
            $label = $argv[2] ?? '';
            $owner = $instance($label);
            $resolved = $context($label);
            $expected = $specifications[$label] ?? null;

            if (
                ! is_array($expected)
                || $owner->app()->sole()->root !== 'public'
                || $resolved->path !== $expected['path']
                || $resolved->executionUser !== $expected['user']
                || $resolved->node->name !== $expected['node']
            ) {
                throw new RuntimeException('Unexpected ORB-211 placement.');
            }

            echo "{$label}:{$resolved->node->name}:{$resolved->executionUser}:{$resolved->path}\n";
        })(),
        'preflight' => (static function () use ($application, $argv, $context): void {
            $label = $argv[2] ?? '';
            $operation = $argv[3] ?? '';
            $capacity = (int) ($argv[4] ?? '0');
            $preflight = $application->make(AppInstanceOperationPreflight::class);

            if ($operation === 'read') {
                $preflight->assertEnvironmentReadable($context($label));

                return;
            }

            if ($operation !== 'write') {
                throw new RuntimeException('Unknown ORB-211 preflight operation.');
            }

            $preflight->assertEnvironmentWritable($context($label), $capacity);
        })(),
        'preflight-observation' => (static function () use ($application, $argv, $context, $decorateSsh): void {
            $kind = $argv[3] ?? '';
            $result = $kind === 'failed'
                ? new CommandResult(255, 'raw remote value', 'raw remote failure', 1, false)
                : new CommandResult(0, "MAYBE\n", '', 1, false);
            $decorateSsh(forcedResult: $result);

            try {
                $application->make(AppInstanceOperationPreflight::class)
                    ->assertEnvironmentWritable($context($argv[2] ?? ''), 4096);
            } catch (ResourceOperationException $exception) {
                if ($exception->errorCode === 'env.write_preflight_failed') {
                    return;
                }

                throw $exception;
            }

            throw new RuntimeException('Unsafe ORB-211 observation passed.');
        })(),
        'write' => $assertWrite($argv[2] ?? '', ($argv[3] ?? '') === 'true'),
        'write-fault' => (static function () use (
            $application,
            $argv,
            $context,
            $decorateSsh,
            $readInput,
        ): void {
            $decorateSsh(fault: $argv[3] ?? '');

            try {
                $application->make(AppInstanceEnvironmentWriter::class)
                    ->write($context($argv[2] ?? ''), $readInput());
            } catch (ResourceOperationException $exception) {
                if ($exception->errorCode === 'env.write_failed' && $exception->getPrevious() === null) {
                    return;
                }

                throw $exception;
            }

            throw new RuntimeException('Injected ORB-211 writer fault passed.');
        })(),
        'write-unconfirmed' => (static function () use (
            $application,
            $argv,
            $context,
            $decorateSsh,
            $readInput,
        ): void {
            $kind = $argv[3] ?? '';
            $decorateSsh(
                fault: $kind === 'sync' ? 'post-rename-sync' : null,
                loseAcknowledgement: $kind === 'after',
                forcedResult: $kind === 'before'
                    ? new CommandResult(255, '', 'Connection closed.', 1, false)
                    : null,
            );
            $result = $application->make(AppInstanceEnvironmentWriter::class)
                ->write($context($argv[2] ?? ''), $readInput());

            if ($result->confirmed || $result->changed !== null) {
                throw new RuntimeException('Unexpected ORB-211 unconfirmed result.');
            }
        })(),
        default => throw new RuntimeException('Unknown ORB-211 fixture command.'),
    };
} catch (ResourceOperationException $exception) {
    fwrite(STDERR, $exception->errorCode."\n");
    exit(70);
} catch (Throwable) {
    fwrite(STDERR, "ORB-211 fixture command failed: {$command}:{$failureStage}\n");
    exit(71);
}
