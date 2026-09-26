<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Data\AppInstances\AppInstanceRemovalData;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\Deployment\DeploymentRelease;
use App\Domain\AppInstances\Deployment\DeploymentResult;
use App\Domain\AppInstances\Removal\AppInstanceRemovalException;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeRemovalException;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Routes\RouteDomain;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Schedules\ScheduleTargetType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\Tools\ToolOperationException;
use App\Http\Requests\Nodes\RemoveNodeRoleInputParser;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Infrastructure\Activity\ActivityShutdownFinalizer;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\Activity\CommandActivityTargetResolver;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Activity;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Schedule;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use UnexpectedValueException;

/**
 * Records one Activity per authorized command. Every request that can change something and every
 * failed request is kept. A successful read is kept once per command, caller, and target path in
 * each READ_SAMPLE_SECONDS. Schedule operations (ADR 0013) and credential reads are always kept
 * (ADR 0152).
 */
final readonly class RecordCommandActivity
{
    public const int READ_SAMPLE_SECONDS = 60;

    public function __construct(
        private CommandDeadline $deadline,
        private CommandActivityInputSanitizer $inputSanitizer,
        private CommandActivityTargetResolver $targetResolver,
        private TopLevelJsonObjectInspector $jsonInspector,
        private RemoveNodeRoleInputParser $removeNodeRoleInputParser,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->deadline->start(Config::float('orbit.command_timeout', 900.0));

        try {
            $response = $this->record($request, $next);
        } catch (Throwable $exception) {
            $this->deadline->clear();

            throw $exception;
        }

        if (! $response instanceof StreamedResponse) {
            $this->deadline->clear();
        }

        return $response;
    }

    private function record(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $activity = $this->start($request);
        // A persisted `running` row is ended at shutdown if a fatal error stops the request first.
        $shutdown = $activity->exists ? ActivityShutdownFinalizer::arm($activity) : null;

        try {
            /** @var Response $response */
            $response = $next($request);

            if ($response instanceof StreamedResponse) {
                return $this->deferStreamCompletion($activity, $request, $response, $startedAt, $shutdown);
            }

            $this->complete($activity, $request, $response, $startedAt);
            $shutdown?->disarm();

            return $response;
        } catch (Throwable $exception) {
            $this->fail($activity, $request, $exception, $startedAt);
            $shutdown?->disarm();

            throw $exception;
        }
    }

    private function deferStreamCompletion(
        Activity $activity,
        Request $request,
        StreamedResponse $response,
        float $startedAt,
        ?ActivityShutdownFinalizer $shutdown,
    ): StreamedResponse {
        $callback = $response->getCallback();

        $response->setCallback(function () use ($activity, $request, $response, $startedAt, $callback, $shutdown): void {
            try {
                $callback();
                $this->complete($activity, $request, $response, $startedAt);
                $shutdown?->disarm();
            } catch (Throwable $exception) {
                $this->fail($activity, $request, $exception, $startedAt);
                $shutdown?->disarm();

                throw $exception;
            } finally {
                $this->deadline->clear();
            }
        });

        return $response;
    }

    private function start(Request $request): Activity
    {
        $requestId = $request->attributes->get('orbit.request_id');
        $command = $request->route()->getName();
        $callerIp = $this->callerIp($request);

        $toolCommand = str_starts_with((string) $command, 'tool:');

        $attributes = [
            'log_name' => 'commands',
            'description' => is_string($command) ? $command : 'unknown',
            'event' => 'command',
            'properties' => [
                ...($toolCommand ? [] : ['method' => $request->method(), 'path' => $request->path()]),
                'input' => $toolCommand ? [] : $this->activityInput($request),
            ],
            'request_id' => is_string($requestId) ? $requestId : '',
            'command' => is_string($command) ? $command : 'unknown',
            'caller_node_id' => $this->callerNodeId($callerIp),
            'caller_ip' => $callerIp,
            'status' => 'running',
        ];

        // A read is written once, when it ends, and only if it fails or is the sampled one.
        if ($this->isRead($request)) {
            return new Activity([...$attributes, 'created_at' => Carbon::now()]);
        }

        return Activity::query()->create($attributes);
    }

    private function isRead(Request $request): bool
    {
        return in_array($request->method(), ['GET', 'HEAD'], true);
    }

    /** @param  array<string, mixed>  $updates */
    private function persist(Activity $activity, Request $request, array $updates): void
    {
        $activity->fill($updates);

        if (! $activity->exists && $activity->status === 'succeeded' && ! $this->samplesRead($activity, $request)) {
            return;
        }

        $activity->save();
    }

    /**
     * Whether this successful read is the one kept for its command, caller, and target in the
     * current window. The request path names the target, such as the Process whose logs were read.
     * A cache failure keeps the row: sampling must never fail a read that worked.
     */
    private function samplesRead(Activity $activity, Request $request): bool
    {
        if (str_starts_with($activity->command, 'schedule:') || str_ends_with($activity->command, ':credentials')) {
            return true;
        }

        try {
            return Cache::add(
                'orbit:activity:read:'.sha1($activity->command.'|'.($activity->caller_ip ?? '').'|'.$request->path()),
                true,
                self::READ_SAMPLE_SECONDS,
            );
        } catch (Throwable $exception) {
            Log::warning('Read activity sampling failed; recording the read.', [
                'command' => $activity->command,
                'exception' => $exception::class,
            ]);

            return true;
        }
    }

    private function complete(
        Activity $activity,
        Request $request,
        Response $response,
        float $startedAt,
    ): void {
        $statusCode = $response->getStatusCode();
        $requestErrorCode = $request->attributes->get('orbit.error_code');
        $commandResult = $request->attributes->get('orbit.command_result');
        $toolException = $request->attributes->get('orbit.tool_exception');
        $errorCode = null;

        if ($statusCode >= 400) {
            $errorCode = is_string($requestErrorCode) ? $requestErrorCode : $this->errorCode($statusCode);
        }

        $updates = [
            'status' => $statusCode < 400 ? 'succeeded' : 'failed',
            'duration_ms' => $this->duration($startedAt),
            'error_code' => $errorCode,
        ];

        $deployment = $request->attributes->get('orbit.deployment_result');

        if ($deployment instanceof DeploymentResult) {
            $updates['status'] = $deployment->succeeded ? 'succeeded' : 'failed';
            $updates['error_code'] = $deployment->failure?->errorCode;
            $updates['properties'] = [
                ...($activity->properties?->toArray() ?? []),
                'deployment' => $this->inputSanitizer->sanitizeProperties([
                    'status' => $deployment->succeeded ? 'succeeded' : 'failed',
                    'selected_release' => $deployment->selectedRelease?->name,
                    'failed_step' => $deployment->failure?->boundary->value,
                    'error_code' => $deployment->failure?->errorCode,
                ]),
            ];
            $commandResult = null;
        }

        $toolActivity = $request->attributes->get('orbit.tool_activity');

        if (is_array($toolActivity)) {
            $updates['properties'] = [
                ...($activity->properties?->toArray() ?? []),
                'tool' => $this->inputSanitizer->sanitizeProperties($toolActivity),
            ];
        }

        if ($toolException instanceof ToolOperationException) {
            $updates['properties'] = [
                ...($activity->properties?->toArray() ?? []),
                'tool' => $this->inputSanitizer->sanitizeProperties($this->toolProjection($toolException)),
            ];
            $commandResult = null;
        }

        $removal = $this->removalProjection($request, $response);

        if (is_array($removal)) {
            $updates['properties'] = [
                ...($activity->properties?->toArray() ?? []),
                'removal' => $this->inputSanitizer->sanitizeProperties($removal),
            ];
        }

        $updates = $this->withSchedule($activity, $request, $updates);
        $updates = $this->withTarget(
            $activity,
            $request,
            $this->withResult($activity, $request, $updates, $commandResult),
            $toolException instanceof ToolOperationException ? $toolException : null,
        );
        $errorMessage = $request->attributes->get('orbit.error_message');

        // The message the API returned, such as a Caddy publisher naming a listen address the Node lacks.
        if ($statusCode >= 400 && is_string($errorMessage) && $errorMessage !== '') {
            $updates['properties'] = [
                ...($updates['properties'] ?? $activity->properties?->toArray() ?? []),
                'error_message' => $this->redact($request, $errorMessage),
            ];
        }

        $this->persist($activity, $request, $updates);
    }

    /** @return array<string, mixed>|null */
    private function removalProjection(Request $request, Response $response): ?array
    {
        $attribute = $request->attributes->get('orbit.app_instance_removal');

        if (is_array($attribute)) {
            /** @var array<string, mixed> $attribute */
            return $attribute;
        }

        if ($request->route()?->getName() !== 'instance:destroy' || $response->getStatusCode() >= 400) {
            return null;
        }

        $content = $response->getContent();

        if (! is_string($content)) {
            return null;
        }

        try {
            $body = json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $data = is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : null;

        if (! is_array($data)) {
            return null;
        }

        $projection = [];

        foreach ([
            'operation_id',
            'id',
            'name',
            'force',
            'status',
            'current_step',
            'total',
            'completed',
            'remaining',
            'failed_step',
            'error_code',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                $projection[$key] = $data[$key];
            }
        }

        return $projection;
    }

    private function fail(
        Activity $activity,
        Request $request,
        Throwable $exception,
        float $startedAt,
    ): void {
        $updates = [
            'status' => 'failed',
            'duration_ms' => $this->duration($startedAt),
            'error_code' => match (true) {
                $exception instanceof ValidationException,
                $exception instanceof NodeRoleValidationException, => 'validation.failed',
                $exception instanceof NodeProvisioningException => $exception->errorCode,
                $exception instanceof NodeRemovalException => $exception->errorCode,
                $exception instanceof RuntimeConvergenceException => $exception->errorCode,
                $exception instanceof AppInstanceRemovalException => $exception->errorCode,
                $exception instanceof ProcessOperationException => $exception->errorCode,
                $exception instanceof ScheduleOperationException => $exception->errorCode,
                $exception instanceof FirewallOperationException => $exception->errorCode,
                $exception instanceof ResourceOperationException => $exception->errorCode,
                $exception instanceof NodeRoleOperationException => $exception->errorCode,
                $exception instanceof RoleAssignmentException => 'node.role_conflict',
                $exception instanceof ToolOperationException => $exception->errorCode,
                $exception instanceof ModelNotFoundException, $exception instanceof NotFoundHttpException => 'http.404',
                default => 'gateway.unhandled',
            },
        ];
        $result = match (true) {
            $exception instanceof NodeProvisioningException => $exception->result,
            $exception instanceof NodeRemovalException => $exception->result,
            $exception instanceof RuntimeConvergenceException => $exception->result,
            $exception instanceof ProcessOperationException => $exception->result,
            $exception instanceof FirewallOperationException => $exception->result,
            $exception instanceof NodeRoleOperationException => $exception->result,
            default => null,
        };

        if ($exception instanceof ToolOperationException) {
            $updates['properties'] = [
                ...($activity->properties?->toArray() ?? []),
                'tool' => $this->inputSanitizer->sanitizeProperties($this->toolProjection($exception)),
            ];
            $result = null;
        }

        if ($exception instanceof AppInstanceRemovalException) {
            $updates['properties'] = [
                ...($activity->properties?->toArray() ?? []),
                'removal' => $this->inputSanitizer->sanitizeProperties(
                    AppInstanceRemovalData::fromModel($exception->removal)->toArray(),
                ),
            ];
        }

        $updates = $this->withSchedule($activity, $request, $updates);

        $this->persist($activity, $request, $this->withTarget(
            $activity,
            $request,
            $this->withResult($activity, $request, $updates, $result),
            $exception instanceof ToolOperationException ? $exception : null,
        ));
    }

    /** @return array<string, mixed> */
    private function toolProjection(ToolOperationException $exception): array
    {
        return [
            'node_id' => $exception->nodeId,
            'manager' => $exception->manager,
            'package' => $exception->package,
            'operation' => $exception->step,
            'outcome' => $exception->outcome->value,
            'version_constraint' => $exception->versionConstraint,
            'error_code' => $exception->errorCode,
        ];
    }

    /**
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function withSchedule(Activity $activity, Request $request, array $updates): array
    {
        $schedule = $this->scheduleForActivity($request);

        if (! $schedule instanceof Schedule) {
            return $updates;
        }

        return [
            ...$updates,
            'properties' => [
                ...($activity->properties?->toArray() ?? []),
                'schedule' => [
                    'id' => $schedule->id,
                    'target_type' => AppInstance::isMorphType($schedule->target_type)
                        ? ScheduleTargetType::AppInstance->value
                        : ScheduleTargetType::Node->value,
                    'target_id' => $schedule->target_id,
                ],
            ],
        ];
    }

    private function scheduleForActivity(Request $request): ?Schedule
    {
        $schedule = $request->attributes->get('orbit.schedule_activity');

        if ($schedule instanceof Schedule) {
            return $schedule;
        }

        $schedule = $request->route('schedule');

        if ($schedule instanceof Schedule) {
            return $schedule;
        }

        if ($request->route()?->getName() !== 'schedule:create') {
            return null;
        }

        $targetType = $request->input('target_type');
        $targetId = $request->input('target_id');
        $name = $request->input('name');

        if (! is_string($targetType) || ! is_int($targetId) || ! is_string($name)) {
            return null;
        }

        $type = ScheduleTargetType::tryFrom($targetType);

        if (! $type instanceof ScheduleTargetType) {
            return null;
        }

        return Schedule::query()
            ->whereIn('target_type', $type->storedTypes())
            ->where('target_id', $targetId)
            ->where('name', $name)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function withResult(
        Activity $activity,
        Request $request,
        array $updates,
        mixed $result,
    ): array {
        if (! $result instanceof CommandResult) {
            return $updates;
        }

        return [
            ...$updates,
            'exit_code' => $result->exitCode,
            'properties' => [
                ...($activity->properties?->toArray() ?? []),
                'stdout' => $this->redact($request, $result->stdout),
                'stderr' => $this->redact($request, $result->stderr),
                'output_truncated' => $result->truncated,
            ],
        ];
    }

    private function callerNodeId(string $callerIp): ?int
    {
        $node = Node::query()
            ->where('wireguard_ip', $callerIp)
            ->where('status', 'active')
            ->first();

        return $node?->id;
    }

    /** @return array<array-key, mixed> */
    private function activityInput(Request $request): array
    {
        $command = $request->route()?->getName();

        if ($command === 'doctor') {
            return $this->doctorInput($request);
        }

        if ($command === 'instance:destroy') {
            return $this->appInstanceRemovalInput($request);
        }

        if ($command === 'instance:register') {
            return $this->appInstanceRegistrationInput($request);
        }

        if ($command === 'instance:clone') {
            return $this->appInstanceCloneInput($request);
        }

        if ($command === 'app:update') {
            return $this->appUpdateInput($request);
        }

        if ($command === 'instance:transfer') {
            return $this->appInstanceTransferInput($request);
        }

        if ($command === 'env:import') {
            return $this->appInstanceEnvironmentImportInput($request);
        }

        if ($command === 'env:update') {
            return [];
        }

        if (
            in_array($command, [
                'instance:deploy-step:create',
                'instance:deploy-step:update',
                'instance:deploy-step:destroy',
                'instance:setup-step:create',
                'instance:setup-step:update',
                'instance:setup-step:destroy',
                'instance:teardown-step:create',
                'instance:teardown-step:update',
                'instance:teardown-step:destroy',
                'instance:setup',
                'instance:update',
            ], true)
        ) {
            return [];
        }

        if ($command === 'instance:deploy') {
            return [];
        }

        if ($command === 'instance:rollback') {
            return $this->appInstanceRollbackInput($request);
        }

        if (is_string($command) && str_contains($request->path(), '-definitions')) {
            return $this->appDefinitionInput($request, $command);
        }

        if (is_string($command) && str_starts_with($command, 'schedule:')) {
            return $this->scheduleInput($request, $command);
        }

        if ($command === 'node:role:remove') {
            return $this->inputSanitizer->sanitizeProperties(
                $this->removeNodeRoleInputParser->safeActivityInput(
                    $request->getContent(),
                    $request->route('role'),
                ),
            );
        }

        if ($command === 'node:role:relocate') {
            return $this->relocateNodeRoleInput($request);
        }

        if ($command !== 'node:role:add') {
            return $this->inputSanitizer->sanitizeProperties($request->collect()->all());
        }

        try {
            $input = $this->jsonInspector->inspect(
                $request->getContent(),
                ['role', 'converge_existing', 'postgres_process_id', 'clickhouse_process_id'],
            );
        } catch (UnexpectedValueException) {
            return [];
        }

        if (! $this->validNodeRoleAdditionInput($input)) {
            return [];
        }

        return $this->inputSanitizer->sanitizeProperties($input);
    }

    /** @return array<string, bool|int|string> */
    private function scheduleInput(Request $request, string $command): array
    {
        if ($command === 'schedule:logs') {
            $lines = $request->query('lines');

            if (! is_string($lines) || preg_match('/\A[1-9][0-9]{0,3}\z/D', $lines) !== 1) {
                return [];
            }

            $count = (int) $lines;

            return $count <= 1000 ? ['lines' => $count] : [];
        }

        if ($command !== 'schedule:create') {
            return [];
        }

        try {
            $input = $this->jsonInspector->inspect($request->getContent(), [
                'target_type',
                'target_id',
                'name',
                'calendar',
                'command',
                'timeout_seconds',
                'start',
            ]);
        } catch (UnexpectedValueException) {
            return [];
        }

        $safe = [];
        $targetType = $input['target_type'] ?? null;
        $targetId = $input['target_id'] ?? null;
        $name = $input['name'] ?? null;
        $timeout = $input['timeout_seconds'] ?? null;
        $start = $input['start'] ?? null;

        if (is_string($targetType) && ScheduleTargetType::tryFrom($targetType) instanceof ScheduleTargetType) {
            $safe['target_type'] = $targetType;
        }

        if (is_int($targetId) && $targetId > 0) {
            $safe['target_id'] = $targetId;
        }

        if (
            is_string($name)
            && strlen($name) <= 63
            && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $name) === 1
        ) {
            $safe['name'] = $name;
        }

        if (is_int($timeout) && $timeout >= 1 && $timeout <= 86_400) {
            $safe['timeout_seconds'] = $timeout;
        }

        if (is_bool($start)) {
            $safe['start'] = $start;
        }

        return $safe;
    }

    /** @return array{release: string}|array{} */
    private function appInstanceRollbackInput(Request $request): array
    {
        try {
            $input = $this->jsonInspector->inspect($request->getContent(), ['release']);
        } catch (UnexpectedValueException) {
            return [];
        }

        $release = $input['release'] ?? null;

        if (! is_string($release) || ! DeploymentRelease::isValidName($release)) {
            return [];
        }

        return ['release' => $release];
    }

    /** @return array{name: string, environments: list<string>}|array{} */
    private function appDefinitionInput(Request $request, string $command): array
    {
        if (! str_ends_with($command, ':create') && ! str_ends_with($command, ':update')) {
            return [];
        }

        $name = $request->input('name');
        $environments = $request->input('environments');

        if (
            ! is_string($name)
            || strlen($name) > 63
            || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $name) !== 1
            || ! is_array($environments)
            || ! array_is_list($environments)
            || $environments === []
            || count($environments) > 2
            || count($environments) !== count(array_unique($environments, SORT_REGULAR))
        ) {
            return [];
        }

        foreach ($environments as $environment) {
            if (! is_string($environment) || ! in_array($environment, ['development', 'production'], strict: true)) {
                return [];
            }
        }

        return ['name' => $name, 'environments' => $environments];
    }

    /** @return array<array-key, mixed> */
    private function appUpdateInput(Request $request): array
    {
        try {
            $input = $this->jsonInspector->inspect(
                $request->getContent(),
                ['slug', 'repository_url', 'default_branch', 'root', 'task_check'],
            );
        } catch (UnexpectedValueException) {
            return [];
        }

        return $this->inputSanitizer->sanitizeProperties($input);
    }

    /** @return array<array-key, mixed> */
    private function appInstanceRemovalInput(Request $request): array
    {
        try {
            $input = $this->jsonInspector->inspect($request->getContent(), ['force']);
        } catch (UnexpectedValueException) {
            return [];
        }

        if (array_key_exists('force', $input) && ! is_bool($input['force'])) {
            return [];
        }

        return $this->inputSanitizer->sanitizeProperties($input);
    }

    /** @return array<array-key, mixed> */
    private function appInstanceRegistrationInput(Request $request): array
    {
        try {
            $input = $this->jsonInspector->inspect($request->getContent(), [
                'source_path',
                'include_worktrees',
                'app_id',
                'app_name',
                'app_slug',
                'default_branch',
                'instance_name',
                'root',
                'domain',
            ]);
        } catch (UnexpectedValueException) {
            return [];
        }

        unset($input['source_path']);

        return $this->inputSanitizer->sanitizeProperties($input);
    }

    /** @return array<array-key, mixed> */
    private function appInstanceCloneInput(Request $request): array
    {
        try {
            $input = $this->jsonInspector->inspect($request->getContent(), [
                'node_id',
                'name',
                'preview_name',
                'branch',
                'sqlite_source_path',
            ]);
        } catch (UnexpectedValueException) {
            return [];
        }

        $nodeId = $input['node_id'] ?? null;
        $name = $input['name'] ?? null;
        $previewName = $input['preview_name'] ?? null;
        $branch = $input['branch'] ?? null;
        $sqliteSourcePath = $input['sqlite_source_path'] ?? null;

        if (
            ! is_int($nodeId)
            || $nodeId < 1
            || ! is_string($name)
            || strlen($name) > 63
            || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $name) !== 1
            || ! is_string($previewName)
            || ! RouteDomain::isValid($previewName)
            || (array_key_exists('branch', $input) && (! is_string($branch) || ! GitBranchName::isValid($branch)))
            || (array_key_exists('sqlite_source_path', $input) && ! is_string($sqliteSourcePath))
        ) {
            return [];
        }

        unset($input['sqlite_source_path']);
        $input['sqlite_selected'] = $sqliteSourcePath !== null;

        return $this->inputSanitizer->sanitizeProperties($input);
    }

    /** @return array<array-key, mixed> */
    private function appInstanceTransferInput(Request $request): array
    {
        try {
            $input = $this->jsonInspector->inspect($request->getContent(), [
                'node_id',
                'name',
                'sqlite_source_path',
            ]);
        } catch (UnexpectedValueException) {
            return [];
        }

        $nodeId = $input['node_id'] ?? null;
        $name = $input['name'] ?? null;
        $sqliteSourcePath = $input['sqlite_source_path'] ?? null;

        if (
            ! is_int($nodeId)
            || $nodeId < 1
            || (array_key_exists('name', $input) && (
                ! is_string($name)
                || strlen($name) > 63
                || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $name) !== 1
            ))
            || (array_key_exists('sqlite_source_path', $input) && ! is_string($sqliteSourcePath))
        ) {
            return [];
        }

        unset($input['sqlite_source_path']);
        $input['sqlite_selected'] = $sqliteSourcePath !== null;

        return $this->inputSanitizer->sanitizeProperties($input);
    }

    /** @return array<array-key, mixed> */
    private function appInstanceEnvironmentImportInput(Request $request): array
    {
        try {
            $input = $this->jsonInspector->inspect($request->getContent(), ['replace']);
        } catch (UnexpectedValueException) {
            return [];
        }

        if (array_key_exists('replace', $input) && ! is_bool($input['replace'])) {
            return [];
        }

        return $input;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function doctorInput(Request $request): array
    {
        try {
            $input = $this->jsonInspector->inspect($request->getContent(), ['node_id', 'families']);
        } catch (UnexpectedValueException) {
            return [];
        }

        if (array_key_exists('node_id', $input)) {
            $nodeId = $input['node_id'];

            if (! is_int($nodeId) || $nodeId < 1) {
                return [];
            }
        }

        if (array_key_exists('families', $input)) {
            $families = $input['families'];

            try {
                $raw = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return [];
            }

            if (! $raw instanceof stdClass || ! is_array($raw->families)) {
                return [];
            }

            if (! is_array($families) || $families === [] || ! array_is_list($families)) {
                return [];
            }

            foreach ($families as $family) {
                if (! is_string($family) || ! DoctorFamily::tryFrom($family) instanceof DoctorFamily) {
                    return [];
                }
            }

            if (count(array_unique($families)) !== count($families)) {
                return [];
            }
        }

        return $this->inputSanitizer->sanitizeProperties($input);
    }

    /** @return array<array-key, mixed> */
    private function relocateNodeRoleInput(Request $request): array
    {
        try {
            $input = $this->jsonInspector->inspect($request->getContent(), ['force', 'from']);
        } catch (UnexpectedValueException) {
            return [];
        }

        $role = $request->route('role');

        if (is_string($role)) {
            $input['role'] = $role;
        }

        if (
            ! is_string($input['role'] ?? null)
            || ! RoleName::tryFrom((string) $input['role']) instanceof RoleName
            || (array_key_exists('force', $input) && ! is_bool($input['force']))
            || (array_key_exists('from', $input) && (! is_int($input['from']) || $input['from'] < 1))
        ) {
            return [];
        }

        return $this->inputSanitizer->sanitizeProperties($input);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function validNodeRoleAdditionInput(array $input): bool
    {
        $role = $input['role'] ?? null;

        return
            is_string($role)
            && RoleName::tryFrom($role) instanceof RoleName
            && (! array_key_exists('converge_existing', $input) || is_bool($input['converge_existing']))
            && (! array_key_exists('postgres_process_id', $input) || is_int($input['postgres_process_id']))
            && (! array_key_exists('clickhouse_process_id', $input) || is_int($input['clickhouse_process_id']));
    }

    private function callerIp(Request $request): string
    {
        $remoteAddress = $request->server('REMOTE_ADDR');

        return is_string($remoteAddress) ? $remoteAddress : '';
    }

    private function duration(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1_000);
    }

    private function errorCode(int $statusCode): string
    {
        return $statusCode === 422 ? 'validation.failed' : "http.{$statusCode}";
    }

    /**
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function withTarget(
        Activity $activity,
        Request $request,
        array $updates,
        ?ToolOperationException $exception = null,
    ): array {
        $target = $this->targetResolver->resolve($request, $exception);

        if ($target !== null) {
            $updates = [...$updates, ...$target];

            if (AppInstance::isMorphType($target['subject_type'] ?? null)) {
                $updates = $this->withAppInstanceSourceLayout($activity, $request, $updates, $target);
            }
        }

        $snapshot = $request->attributes->get('orbit.target_node_snapshot');

        if (
            ! is_array($snapshot)
            || ! is_int($snapshot['id'] ?? null)
            || ! is_string($snapshot['name'] ?? null)
        ) {
            return $updates;
        }

        /** @var array<string, mixed> $properties */
        $properties = is_array($updates['properties'] ?? null)
            ? $updates['properties']
            : $activity->properties?->toArray() ?? [];

        return [
            ...$updates,
            'properties' => [
                ...$properties,
                'target_node' => [
                    'id' => $snapshot['id'],
                    'name' => $snapshot['name'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $updates
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function withAppInstanceSourceLayout(
        Activity $activity,
        Request $request,
        array $updates,
        array $target,
    ): array {
        $bound = $request->route('instance');
        $appInstance = $bound instanceof AppInstance
            ? $bound
            : AppInstance::query()->find($target['subject_id'] ?? null);

        if (! $appInstance instanceof AppInstance) {
            return $updates;
        }

        /** @var array<string, mixed> $properties */
        $properties = is_array($updates['properties'] ?? null)
            ? $updates['properties']
            : $activity->properties?->toArray() ?? [];

        return [
            ...$updates,
            'properties' => [
                ...$properties,
                'source_layout' => $appInstance->source_layout,
                'branch_override' => $appInstance->branch_override,
                'migration_required' => $appInstance->migration_required,
            ],
        ];
    }

    private function redact(Request $request, string $output): string
    {
        $redacted = $this->inputSanitizer->redactText($output);
        $values = $this->environmentSecretValues($request);

        return str_replace(search: $values, replace: '[REDACTED]', subject: $redacted);
    }

    /**
     * @return list<string>
     */
    private function environmentSecretValues(Request $request): array
    {
        $process = $request->route('process');
        $storedEnvironment = $process instanceof Process
            ? $process->runtime_config['environment'] ?? null
            : null;
        /** @var list<string> $values */
        $values = [];

        $submittedValue = $request->input('value');
        if (is_string($submittedValue) && $submittedValue !== '') {
            $values[] = $submittedValue;
        }

        foreach ([$request->input('environment'), $storedEnvironment] as $environment) {
            if (! is_array($environment)) {
                continue;
            }

            foreach ($environment as $value) {
                if (! is_string($value) || $value === '') {
                    continue;
                }

                $values[] = $value;
            }
        }

        $steps = $request->input('steps');

        if (is_array($steps)) {
            foreach ($steps as $step) {
                $command = is_array($step) ? $step['command'] ?? null : null;

                if (is_string($command) && $command !== '') {
                    $values[] = $command;
                }
            }
        }

        $values = array_values(array_unique($values));
        usort($values, static fn (string $first, string $second): int => strlen($second) <=> strlen($first));

        return $values;
    }
}
