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
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolOperationException;
use App\Http\Requests\Nodes\RemoveNodeRoleInputParser;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\Activity\CommandActivityTargetResolver;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Activity;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use UnexpectedValueException;

final readonly class RecordCommandActivity
{
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

        try {
            /** @var Response $response */
            $response = $next($request);

            if ($response instanceof StreamedResponse) {
                return $this->deferStreamCompletion($activity, $request, $response, $startedAt);
            }

            $this->complete($activity, $request, $response, $startedAt);

            return $response;
        } catch (Throwable $exception) {
            $this->fail($activity, $request, $exception, $startedAt);

            throw $exception;
        }
    }

    private function deferStreamCompletion(
        Activity $activity,
        Request $request,
        StreamedResponse $response,
        float $startedAt,
    ): StreamedResponse {
        $callback = $response->getCallback();

        $response->setCallback(function () use ($activity, $request, $response, $startedAt, $callback): void {
            try {
                $callback();
                $this->complete($activity, $request, $response, $startedAt);
            } catch (Throwable $exception) {
                $this->fail($activity, $request, $exception, $startedAt);

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

        return Activity::query()->create([
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
        ]);
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

        $activity->update($this->withTarget(
            $activity,
            $request,
            $this->withResult($activity, $request, $updates, $commandResult),
            $toolException instanceof ToolOperationException ? $toolException : null,
        ));
    }

    /** @return array<string, mixed>|null */
    private function removalProjection(Request $request, Response $response): ?array
    {
        $attribute = $request->attributes->get('orbit.app_instance_removal');

        if (is_array($attribute)) {
            /** @var array<string, mixed> $attribute */
            return $attribute;
        }

        if ($request->route()?->getName() !== 'instance:remove' || $response->getStatusCode() >= 400) {
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

        $activity->update($this->withTarget(
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

        if ($command === 'doctor:run') {
            return $this->doctorInput($request);
        }

        if ($command === 'instance:remove') {
            return $this->appInstanceRemovalInput($request);
        }

        if ($command === 'instance:register') {
            return $this->appInstanceRegistrationInput($request);
        }

        if ($command === 'instance:deployment-layout:prepare') {
            return $this->appInstanceDeploymentLayoutInput($request);
        }

        if ($command === 'instance:environment:import') {
            return $this->appInstanceEnvironmentImportInput($request);
        }

        if ($command === 'instance:environment:update') {
            return [];
        }

        if ($command === 'instance:deployment-config:update') {
            return [];
        }

        if ($command === 'instance:deployment:store') {
            return [];
        }

        if ($command === 'instance:rollback:store') {
            return $this->appInstanceRollbackInput($request);
        }

        if (
            is_string($command)
            && (
                str_starts_with($command, 'process-definition:')
                || str_starts_with($command, 'schedule-definition:')
            )
        ) {
            return $this->appDefinitionInput($request, $command);
        }

        if ($command === 'node:role:remove') {
            return $this->inputSanitizer->sanitizeProperties(
                $this->removeNodeRoleInputParser->safeActivityInput(
                    $request->getContent(),
                    $request->route('role'),
                ),
            );
        }

        if ($command !== 'node:role:add') {
            return $this->inputSanitizer->sanitizeProperties($request->collect()->all());
        }

        try {
            $input = $this->jsonInspector->inspect($request->getContent(), ['role', 'converge_existing']);
        } catch (UnexpectedValueException) {
            return [];
        }

        if (! $this->validNodeRoleAdditionInput($input)) {
            return [];
        }

        return $this->inputSanitizer->sanitizeProperties($input);
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
        if (! str_ends_with($command, ':new') && ! str_ends_with($command, ':update')) {
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

    /** @return array{sqlite_selected: bool}|array{} */
    private function appInstanceDeploymentLayoutInput(Request $request): array
    {
        try {
            $input = $this->jsonInspector->inspect($request->getContent(), ['sqlite_source_path']);
        } catch (UnexpectedValueException) {
            return [];
        }

        return ['sqlite_selected' => array_key_exists('sqlite_source_path', $input)];
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
                'hostname',
            ]);
        } catch (UnexpectedValueException) {
            return [];
        }

        unset($input['source_path']);

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

    /**
     * @param  array<string, mixed>  $input
     */
    private function validNodeRoleAdditionInput(array $input): bool
    {
        $role = $input['role'] ?? null;

        return
            is_string($role)
            && RoleName::tryFrom($role) instanceof RoleName
            && (! array_key_exists('converge_existing', $input) || is_bool($input['converge_existing']));
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

            if (($target['subject_type'] ?? null) === AppInstance::class) {
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
