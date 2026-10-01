<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Actions\Processes\AddProcessAction;
use App\Data\DatabaseServers\CreateDatabaseServerData;
use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\DatabaseServerProcess;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Creates a Database server in four steps: the record with a generated root password, the Docker
 * Node Process started with MYSQL_ROOT_PASSWORD, the wait for the root login, and the removal of
 * the variable from the Process. Each step leaves state that a retry of the same request reads,
 * so a retry continues from the first step that did not finish.
 */
final readonly class CreateDatabaseServerAction
{
    public const int READY_TIMEOUT_SECONDS = 120;

    private const int ROOT_PASSWORD_LENGTH = 48;

    public function __construct(
        private ProcessTargetResolver $targets,
        private AddProcessAction $processes,
        private ProcessRuntimeLease $lease,
        private ProcessRuntimeManager $runtime,
        private DatabaseServerAdmin $admin,
    ) {}

    /** @return array{server: DatabaseServer, created: bool} */
    public function execute(CreateDatabaseServerData $data): array
    {
        $node = Node::query()->findOrFail($data->nodeId);
        $this->targets->forNodeAdmission($node);

        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new ResourceOperationException(
                errorCode: 'process.wireguard_ip_missing',
                message: "Node [{$node->name}] has no WireGuard address.",
                status: 422,
            );
        }

        $reserved = $this->reserve($data, $node);
        $server = $reserved['server'];

        if ($server->status !== LifecycleStatus::Active) {
            $this->provision($server, $node);
        }

        return ['server' => $server->refresh(), 'created' => $reserved['created']];
    }

    /** @return array{server: DatabaseServer, created: bool} */
    private function reserve(CreateDatabaseServerData $data, Node $node): array
    {
        try {
            return DB::transaction(function () use ($data, $node): array {
                $existing = DatabaseServer::query()
                    ->where('slug', $data->slug)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof DatabaseServer) {
                    if (
                        $existing->node_id !== $node->id
                        || $existing->tag !== $data->tag
                        || $existing->port !== $data->port
                    ) {
                        throw $this->slugConflict($data->slug);
                    }

                    return ['server' => $existing, 'created' => false];
                }

                $this->assertPortFree($node, $data->port);

                $server = DatabaseServer::query()->create([
                    'slug' => $data->slug,
                    'node_id' => $node->id,
                    'process_id' => null,
                    'tag' => $data->tag,
                    'port' => $data->port,
                    'root_password' => Str::random(self::ROOT_PASSWORD_LENGTH),
                    'status' => LifecycleStatus::Provisioning,
                ]);

                return ['server' => $server, 'created' => true];
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->slugConflict($data->slug);
        }
    }

    private function provision(DatabaseServer $server, Node $node): void
    {
        $server->update([
            'status' => LifecycleStatus::Provisioning,
            'failed_step' => null,
            'error_code' => null,
        ]);

        $process = $server->process;

        if (! $process instanceof Process || DatabaseServerProcess::holdsRootPassword($process)) {
            $process = $this->step($server, 'process', fn (): Process => $this->processes->execute(
                DatabaseServerProcess::data($server, $node, $server->root_password),
            )['process']);
            $server->update(['process_id' => $process->id]);
            $server->setRelation('process', $process);

            $this->step($server, 'readiness', fn () => $this->waitUntilReady($server));
            $this->step($server, 'root_password_removal', fn () => $this->forgetRootPassword($process));
        } elseif ($process->status !== LifecycleStatus::Active) {
            $this->step($server, 'root_password_removal', fn () => $this->forgetRootPassword($process));
        }

        $this->step($server, 'readiness', fn () => $this->waitUntilReady($server));

        $server->update([
            'status' => LifecycleStatus::Active,
            'failed_step' => null,
            'error_code' => null,
        ]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function step(DatabaseServer $server, string $step, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ResourceOperationException|ProcessOperationException $exception) {
            $server->update([
                'status' => LifecycleStatus::Failed,
                'failed_step' => $step,
                'error_code' => $exception->errorCode,
            ]);

            throw $exception;
        }
    }

    private function waitUntilReady(DatabaseServer $server): void
    {
        if ($this->admin->waitUntilReady($server, self::READY_TIMEOUT_SECONDS)) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'database.server_start_failed',
            message: "Database server [{$server->slug}] did not accept the root login within ".self::READY_TIMEOUT_SECONDS.' seconds.',
            status: 502,
        );
    }

    /** Remove MYSQL_ROOT_PASSWORD from the Process and recreate its container. The data volume keeps the password. */
    private function forgetRootPassword(#[SensitiveParameter] Process $process): void
    {
        $this->lease->run($process, function (Process $fresh): void {
            $runtimeConfig = $fresh->runtime_config;
            $environment = is_array($runtimeConfig['environment'] ?? null) ? $runtimeConfig['environment'] : [];
            unset($environment[DatabaseServerProcess::ROOT_PASSWORD_VARIABLE]);

            $fresh->fill([
                'runtime_config' => [...$runtimeConfig, 'environment' => $environment],
                'status' => LifecycleStatus::Provisioning,
                'failed_step' => null,
                'error_code' => null,
            ])->save();

            try {
                $this->runtime->converge($fresh);
            } catch (ProcessOperationException $exception) {
                $fresh->update([
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => $exception->step,
                    'error_code' => $exception->errorCode,
                ]);

                throw $exception;
            }

            $fresh->update(['status' => LifecycleStatus::Active]);
        });
    }

    private function assertPortFree(Node $node, int $port): void
    {
        $instanceIds = Instance::query()->where('node_id', $node->id)->pluck('id')->all();
        $processes = Process::query()
            ->where('runtime', ProcessRuntime::Docker)
            ->where(function ($query) use ($node, $instanceIds): void {
                $query->where(function ($owned) use ($node): void {
                    $owned->where('owner_type', Node::class)->where('owner_id', $node->id);
                })->orWhere(function ($owned) use ($instanceIds): void {
                    $owned->whereIn('owner_type', Instance::morphTypes())->whereIn('owner_id', $instanceIds);
                });
            })
            ->get();

        foreach ($processes as $process) {
            $ports = $process->runtime_config['ports'] ?? [];

            foreach (is_array($ports) ? $ports : [] as $spec) {
                $mapping = is_string($spec) ? DockerPublishedPort::parse($spec) : null;

                if ($mapping instanceof DockerPublishedPort && $mapping->publishedPort === $port) {
                    throw new ResourceOperationException(
                        errorCode: 'database.server_port_in_use',
                        message: "Process [{$process->name}] on Node [{$node->name}] already publishes port {$port}.",
                        status: 409,
                    );
                }
            }
        }
    }

    private function slugConflict(string $slug): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'database.server_slug_conflict',
            message: "Database server [{$slug}] already exists with a different Node, tag, or port.",
            status: 409,
        );
    }
}
