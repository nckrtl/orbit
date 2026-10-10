<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Data\Instances\InstanceRemovalData;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\DeploymentResult;
use App\Domain\Instances\Removal\InstanceRemovalException;
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
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Schedule;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
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
        $endDeadline = $this->deadline->startRequest(Config::float('orbit.command_timeout', 570.0), CommandDeadline::CleanupReserveSeconds);

        try {
            $response = $this->record($request, $next, $endDeadline);
        } catch (Throwable $exception) {
            $endDeadline();

            throw $exception;
        }

        if (! $response instanceof StreamedResponse) {
            $endDeadline();
        }

        return $response;
    }

    /** @param Closure(): void $endDeadline */
    private function record(Request $request, Closure $next, Closure $endDeadline): Response
    {
        $startedAt = microtime(true);
        $activity = $this->start($request);
        // A persisted `running` row is ended at shutdown if a fatal error stops the request first.
        $shutdown = $activity->exists ? ActivityShutdownFinalizer::arm($activity) : null;

        try {
            // A request nested in one that has run out of forward time, such as a late call of an MCP
            // tool batch, fails here instead of starting work that PHP-FPM would end.
            $this->deadline->cap(Config::float('orbit.command_timeout', 570.0));
            $response = $next($request);

            if ($response instanceof StreamedResponse) {
                return $this->deferStreamCompletion($activity, $request, $response, $startedAt, $shutdown, $endDeadline);
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

    /** @param Closure(): void $endDeadline */
    private function deferStreamCompletion(
        Activity $activity,
        Request $request,
        StreamedResponse $response,
        float $startedAt,
        ?ActivityShutdownFinalizer $shutdown,
        Closure $endDeadline,
    ): StreamedResponse {
        $callback = $response->getCallback();

        $response->setCallback(function () use ($activity, $request, $response, $startedAt, $callback, $shutdown, $endDeadline): void {
            try {
                if (! $callback instanceof Closure) {
                    throw new \LogicException('The Response callback must be set.');
                }

                $callback();
                $this->complete($activity, $request, $response, $startedAt);
                $shutdown?->disarm();
            } catch (Throwable $exception) {
                $this->fail($activity, $request, $exception, $startedAt);
                $shutdown?->disarm();

                throw $exception;
            } finally {
                $endDeadline();
            }
        });

        return $response;
    }

    private function start(Request $request): Activity
    {
        $requestId = $request->attributes->get('orbit.request_id');
        $route = $request->route();
        if (! $route instanceof Route) {
            throw new \LogicException('Command activity requires a matched route.');
        }
        $command = $route->getName();
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

        $environmentTesting = $request->attributes->get('orbit.environment_testing');

        if (is_array($environmentTesting)) {
            $updates['properties'] = [
                ...($activity->properties?->toArray() ?? []),
                'testing' => $this->inputSanitizer->sanitizeProperties($environmentTesting),
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
            $properties = $updates['properties'] ?? null;
            $existingProperties = is_array($properties)
                ? $properties
                : ($activity->properties?->toArray() ?? []);
            $updates['properties'] = [
                ...$existingProperties,
                'error_message' => $this->redact($request, $errorMessage),
            ];
        }

        $this->persist($activity, $request, $updates);
    }

    /** @return array<string, mixed>|null */
    private function removalProjection(Request $request, Response $response): ?array
    {
        $attribute = $request->attributes->get('orbit.instance_removal');

        if (is_array($attribute)) {
            return ValidatedData::object($attribute);
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

        return ValidatedData::object($projection);
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
                $exception instanceof InstanceRemovalException => $exception->errorCode,
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

        if ($exception instanceof InstanceRemovalException) {
            $updates['properties'] = [
                ...($activity->properties?->toArray() ?? []),
                'removal' => $this->inputSanitizer->sanitizeProperties(
                    InstanceRemovalData::fromModel($exception->removal)->toArray(),
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
                    'target_type' => Instance::isMorphType($schedule->target_type)
                        ? ScheduleTargetType::Instance->value
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

        if (is_string($command) && str_starts_with($command, 'project:document:')) {
            // Body parsing belongs after Node access, and document bytes never enter Activity.
            return [];
        }

        if ($command === 'doctor') {
            return $this->doctorInput($request);
        }

        if ($command === 'instance:destroy') {
            return $this->instanceRemovalInput($request);
        }

        if ($command === 'instance:register') {
            return $this->instanceRegistrationInput($request);
        }

        if ($command === 'instance:clone') {
            return $this->instanceCloneInput($request);
        }

        if ($command === 'project:update') {
            return $this->appUpdateInput($request);
        }

        if ($command === 'instance:transfer') {
            return $this->instanceTransferInput($request);
        }

        if ($command === 'env:import') {
            return $this->instanceEnvironmentImportInput($request);
        }

        if ($command === 'env:update') {
            return [];
        }

        if ($command === 'conn:environment:register') {
            // The admin session never enters Activity.
            return $this->inputSanitizer->sanitizeProperties($request->only(['environment_id', 'label', 'url']));
        }

        if ($command === 'conn:profile:settings:update') {
            // A settings document can hold workspace pictures; Activity keeps the version it replaced.
            return $this->inputSanitizer->sanitizeProperties($request->only(['version']));
        }

        if (
            in_array($command, [
                'project:dev-deploy-step:create',
                'project:dev-deploy-step:update',
                'project:dev-deploy-step:destroy',
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
            return $this->instanceRollbackInput($request);
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
    private function instanceRollbackInput(Request $request): array
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
                ['slug', 'repository_url', 'default_branch', 'root', 'task_check', 'task_workspace_routed', 'task_compute', 'review_and_merge', 'merge_check'],
            );
        } catch (UnexpectedValueException) {
            return [];
        }

        return $this->inputSanitizer->sanitizeProperties($input);
    }

    /** @return array<array-key, mixed> */
    private function instanceRemovalInput(Request $request): array
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
    private function instanceRegistrationInput(Request $request): array
    {
        try {
            $input = $this->jsonInspector->inspect($request->getContent(), [
                'source_path',
                'include_worktrees',
                'project_id',
                'project_name',
                'project_slug',
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
    private function instanceCloneInput(Request $request): array
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
    private function instanceTransferInput(Request $request): array
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
    private function instanceEnvironmentImportInput(Request $request): array
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

            if (Instance::isMorphType($target['subject_type'] ?? null)) {
                $updates = $this->withInstanceSourceLayout($activity, $request, $updates, $target);
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
    private function withInstanceSourceLayout(
        Activity $activity,
        Request $request,
        array $updates,
        array $target,
    ): array {
        $bound = $request->route('instance');
        $instance = $bound instanceof Instance
            ? $bound
            : Instance::query()->find($target['subject_id'] ?? null);

        if (! $instance instanceof Instance) {
            return $updates;
        }

        $properties = is_array($updates['properties'] ?? null)
            ? $updates['properties']
            : $activity->properties?->toArray() ?? [];

        return [
            ...$updates,
            'properties' => [
                ...$properties,
                'source_layout' => $instance->source_layout,
                'branch_override' => $instance->branch_override,
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
