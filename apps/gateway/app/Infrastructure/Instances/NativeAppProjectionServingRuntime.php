<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Apps\AppProjectionIdentity;
use App\Domain\Instances\Apps\AppProjectionReceipt;
use App\Domain\Instances\Apps\AppProjectionStepAdapter;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\InstanceAppProjection;
use App\Models\InstanceAppProjectionStep;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Bound internal adapter. Route reservations and public map/profile publication belong to the parent.
 *
 * Plans carry name-keyed, complete effective app configurations and classified source profiles.
 * resources.serving is app-keyed {route_id, domain, app_identity}; the parent reserves these Routes
 * through the existing Route owner, without activating an added app's public association.
 * placement.checkout_path and the absolute selected_release freeze the source identities.
 * Environment files remain owned by NativeAppProjectionEnvironment; this adapter owns cached APP_URL.
 */
final readonly class NativeAppProjectionServingRuntime implements AppProjectionStepAdapter
{
    public function __construct(
        private DevelopmentSshExecutor $ssh,
        private ManagedUserAccountResolver $accounts,
        private RemoteDevelopmentInstanceConfigurator $source,
        private RemoteAppDevPhpFpmManager $php,
        private RemoteAppDevCertificateManager $certificates,
        private RemoteAppDevCaddyManager $caddy,
        private DnsmasqPrivateDnsManager $dns,
        private DevelopmentProjectionOperationLock $projections,
    ) {}

    public function mutate(InstanceAppProjectionStep $step): AppProjectionReceipt
    {
        return $this->execute($step, false);
    }

    public function recover(InstanceAppProjectionStep $step): AppProjectionReceipt
    {
        return $this->execute($step, true);
    }

    /** @return array<string, string> */
    public function targetIdentities(InstanceAppProjection $projection, string $app): array
    {
        $view = new CommittedAppServingView;
        $resource = $view->resources($projection)[$app] ?? null;
        if ($resource === null) {
            $this->conflict();
        }

        return ['route_id' => (string) $resource['route_id'], 'domain' => $resource['domain'],
            'serving_identity' => AppProjectionIdentity::digest($resource), 'app_plan' => AppProjectionIdentity::digest([
                $view->configuration($projection, 'before', $app), $view->configuration($projection, 'candidate', $app),
                $projection->plan['before_profiles'], $projection->plan['candidate_profiles'],
                $projection->plan['placement'], $projection->plan['selected_release'],
            ])];
    }

    private function execute(InstanceAppProjectionStep $step, bool $recover): AppProjectionReceipt
    {
        if (DB::transactionLevel() > 0) {
            throw new LogicException('Native serving projection requires committed intent.');
        }

        return $this->projections->run(function () use ($step, $recover): AppProjectionReceipt {
            $projection = InstanceAppProjection::query()->findOrFail($step->instance_app_projection_id);
            $instance = Instance::query()->with(['project', 'node.roles'])->findOrFail($projection->instance_id);
            $intent = $step->intent;
            $app = $intent['app'] ?? null;
            $phase = $intent['phase'] ?? null;
            if (! is_string($app) || ! in_array($phase, ['prepare', 'restore', 'cleanup'], true) || ($intent['resource'] ?? null) !== 'serving'
                || $step->plan_digest !== $projection->plan_digest || $projection->node_id !== $instance->node_id
                || $projection->active_instance_id !== $instance->id || ! $instance->placedOnAppDev() || $instance->task_workspace_routed === false
                || AppProjectionIdentity::digest($projection->plan) !== $projection->plan_digest || ! is_array($intent['targets'] ?? null)
                || ! is_array($projection->plan['placement'] ?? null) || ($projection->plan['placement']['checkout_path'] ?? null) !== $instance->checkout_path) {
                $this->conflict();
            }
            foreach ($this->targetIdentities($projection, $app) as $key => $identity) {
                if (($intent['targets'][$key] ?? null) !== $identity) {
                    $this->conflict();
                }
            }
            $targets = $this->strings($intent['targets']);
            $view = new CommittedAppServingView;
            $route = Route::query()->with('cluster.routerAssignment.node')->findOrFail((int) $targets['route_id']);
            if ($route->project_id !== $instance->project_id || $route->app !== $app || $route->domain !== $targets['domain'] || $route->targets()->count() !== 1 || ! $route->targets()->where('instance_id', $instance->id)->exists()) {
                $this->conflict();
            }
            if ($phase === 'cleanup') {
                $published = collect($instance->effectiveApps())->firstWhere('name', $app);
                $candidate = $view->configuration($projection, 'candidate', $app);
                if ($published !== $candidate) {
                    $this->conflict();
                }
                if ($candidate !== null) {
                    if (! $route->isAuthoritative() || ! $route->sites_published) {
                        $this->conflict();
                    }
                    $profile = $view->profile($projection, 'candidate', $app);
                    $runtime = $instance->runtimeForApp($app);
                    if ($runtime['php_version'] !== $profile['php_version'] || $runtime['laravel'] !== $profile['laravel']) {
                        $this->conflict();
                    }
                }
            }
            $account = $this->accounts->resolve($instance->node);
            $payload = ['binding' => $this->binding($step), 'targets' => $intent['targets'], 'user' => $account->user,
                'phase' => $phase, 'recover' => $recover, 'finish' => false, 'checkout_path' => $instance->checkout_path, 'url' => 'https://'.$route->domain, 'cache_paths' => []];
            if ($phase !== 'prepare') {
                $id = $intent['targets'][$phase === 'restore' ? 'restores_step_id' : 'cleans_step_id'] ?? null;
                $source = is_string($id) ? InstanceAppProjectionStep::query()->find($id) : null;
                if (! $source instanceof InstanceAppProjectionStep || $source->instance_app_projection_id !== $projection->id
                    || ($source->intent['phase'] ?? null) !== 'prepare' || ($source->intent['app'] ?? null) !== $app
                    || ($source->intent['resource'] ?? null) !== 'serving') {
                    $this->conflict();
                }
                if ($phase === 'restore' && ($receipt = new AppProjectionServingAccess($this->ssh)->restoreBeforeFiles($step, $source, $instance->node)) !== null) {
                    return $receipt;
                }
                $payload['source_receipt'] = $source->receipt_id;
                $payload['source_binding'] = $this->binding($source);
            } else {
                $configuration = $view->configuration($projection, 'candidate', $app);
                if (is_array($configuration)) {
                    $profile = $this->source->inspectConfiguration($instance, $configuration);
                    $expected = $view->profile($projection, 'candidate', $app);
                    if (($expected['php_version'] ?? null) !== $profile->phpVersion || $expected['laravel'] !== $profile->laravel) {
                        $this->conflict();
                    }
                    if ($profile->laravel) {
                        $payload['cache_paths'][] = ApplicationDirectory::resolvePath($instance->checkout_path, $configuration['path']).'/bootstrap/cache/config.php';
                        if ($instance->development_release_layout) {
                            $release = $projection->plan['selected_release'];
                            if (! is_string($release) || ! str_starts_with($release, $instance->checkout_path.'/releases/')
                                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', substr($release, strlen($instance->checkout_path.'/releases/'))) !== 1) {
                                $this->conflict();
                            }
                            $this->ssh->execute($instance->node, new RemoteCommand(['bash', '-seu', '--', $instance->checkout_path.'/current', $release],
                                'test "$(realpath -e -- "$1")" = "$2"'), 'serving-release-inspect', 'app.projection_receipt_conflict');
                            $releaseProfile = $this->source->inspectConfiguration($instance, $configuration, $release);
                            if ($releaseProfile->phpVersion !== $profile->phpVersion || $releaseProfile->laravel !== $profile->laravel) {
                                $this->conflict();
                            }
                            $payload['cache_paths'][] = ApplicationDirectory::resolvePath($release, $configuration['path']).'/bootstrap/cache/config.php';
                        }
                    }
                }
            }
            if ($phase === 'prepare') {
                new AppProjectionServingAccess($this->ssh)->prepare($step, $instance->node, $payload['cache_paths']);
                $payload['access_binding'] = AppProjectionServingAccess::binding($step);
            }
            $this->checkpoint($instance, $payload);
            if ($phase === 'prepare' && $view->configuration($projection, 'candidate', $app) !== null && $step->status !== 'complete') {
                $this->certificates->convergeInstance($instance, $route);
                $router = $route->cluster?->routerAssignment?->node;
                if ($router instanceof Node && ! $router->is($instance->node)) {
                    $this->certificates->convergeRouteRouter($route, $router);
                    app(NativeDevelopmentRouteProjector::class)->prepareFirewallPolicy($instance, $route);
                }
            }
            // The committed checkpoint, not a worker-local override, selects every independent build.
            if ($phase === 'prepare' && in_array($step->status, ['intended', 'access-ready'], true)) {
                $step->update(['status' => 'rendering']);
            }
            if ($step->status === 'complete') {
                $this->php->verify($instance->node);
            } else {
                $this->php->converge($instance->node);
            }
            $this->caddy->build($instance->node);
            $router = $route->cluster?->routerAssignment?->node;
            if ($router instanceof Node && ! $router->is($instance->node)) {
                $this->caddy->build($router);
            }
            $this->dns->converge();
            // Retire only this app's certificate after every site reader has withdrawn it.
            if ($phase === 'restore' && $view->configuration($projection, 'before', $app) === null
                || $phase === 'cleanup' && $view->configuration($projection, 'candidate', $app) === null) {
                if ($phase === 'cleanup') {
                    $route->update(['status' => RouteStatus::Retiring, 'sites_published' => false]);
                }
                $this->certificates->removeApp($instance, $route);
                if ($router instanceof Node && ! $router->is($instance->node)) {
                    $this->certificates->removeRouteRouter($route, $router);
                }
            }
            $payload['recover'] = true;
            $payload['finish'] = true;
            $evidence = $this->checkpoint($instance, $payload);
            $receipt = new AppProjectionReceipt($step->id, $projection->id, $step->receipt_id, $step->plan_digest,
                AppProjectionIdentity::digest($step->intent), $evidence['result_fingerprint'], $evidence['snapshots'], $evidence['complete'], $targets, $evidence['artifacts']);
            if (! $receipt->matches($step)) {
                $this->conflict();
            }

            return $receipt;
        });
    }

    /** @return array<string, mixed> */
    private function binding(InstanceAppProjectionStep $step): array
    {
        return ['step_id' => $step->id, 'projection_id' => $step->instance_app_projection_id, 'receipt_id' => $step->receipt_id,
            'plan_digest' => $step->plan_digest, 'intent_digest' => AppProjectionIdentity::digest($step->intent),
            'owner' => $step->intent['project_update_id'] ?? $step->intent['instance_app_update_id'], 'app' => $step->intent['app'],
            'instance_id' => $step->intent['instance_id'], 'node_id' => $step->intent['node_id']];
    }

    /** @param array<string, mixed> $payload
     * @return array{result_fingerprint: string, snapshots: array<string, string>, complete: bool, artifacts: array<string, array{created: bool, protection_fingerprint: string, result_fingerprint: string}>}
     */
    private function checkpoint(Instance $instance, array $payload): array
    {
        try {
            $result = $this->ssh->execute($instance->node, new RemoteCommand(arguments: ['sudo', 'python3', '-c', AppProjectionServingProgram::script()],
                protectedInput: ProtectedInput::fromString(json_encode($payload, JSON_THROW_ON_ERROR)), maxOutputBytes: 16384),
                'app-projection-serving', 'app.projection_receipt_conflict');
        } catch (RuntimeConvergenceException) {
            $this->conflict();
        }
        $value = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($value) || ($value['binding'] ?? null) !== null
            || ! is_string($value['result_fingerprint'] ?? null) || ! is_bool($value['complete'] ?? null) || ! is_array($value['snapshots'] ?? null) || ! is_array($value['artifacts'] ?? null)) {
            $this->conflict();
        }

        $binding = $payload['binding'] ?? null;
        if (! is_array($binding) || ($value['targets'] ?? null) !== ($payload['targets'] ?? null)) {
            $this->conflict();
        }
        foreach ($binding as $key => $identity) {
            if (($value[$key] ?? null) !== $identity) {
                $this->conflict();
            }
        }
        $artifacts = [];
        foreach ($value['artifacts'] as $key => $artifact) {
            if (! is_string($key) || ! is_array($artifact) || ! is_bool($artifact['created'] ?? null)
                || ! is_string($artifact['protection_fingerprint'] ?? null) || ! is_string($artifact['result_fingerprint'] ?? null)) {
                $this->conflict();
            }
            $artifacts[$key] = ['created' => $artifact['created'], 'protection_fingerprint' => $artifact['protection_fingerprint'], 'result_fingerprint' => $artifact['result_fingerprint']];
        }

        return ['result_fingerprint' => $value['result_fingerprint'], 'snapshots' => $this->strings($value['snapshots']), 'complete' => $value['complete'], 'artifacts' => $artifacts];
    }

    /** @param array<mixed> $values
     * @return array<string, string>
     */
    private function strings(array $values): array
    {
        $strings = [];
        foreach ($values as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                $this->conflict();
            }
            $strings[$key] = $value;
        }

        return $strings;
    }

    private function conflict(): never
    {
        throw new ResourceOperationException('app.projection_receipt_conflict', 'The native serving projection does not match its protected ownership evidence.', 409);
    }
}
