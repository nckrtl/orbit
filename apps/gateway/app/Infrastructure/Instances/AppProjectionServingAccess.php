<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Instances\Apps\AppProjectionIdentity;
use App\Domain\Instances\Apps\AppProjectionReceipt;
use App\Domain\Projects\ProjectApps;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\InstanceAppProjection;
use App\Models\InstanceAppProjectionStep;
use App\Models\Node;
use Illuminate\Support\Collection;

/** Access precedes file receipts. A durable checkpoint prevents ACL repair from hiding foreign changes. */
final readonly class AppProjectionServingAccess
{
    public function __construct(private DevelopmentSshExecutor $ssh) {}

    /** @param list<string> $paths */
    public function prepare(InstanceAppProjectionStep $step, Node $node, array $paths = []): bool
    {
        $projection = InstanceAppProjection::query()->findOrFail($step->instance_app_projection_id);
        if (new CommittedAppServingView()->resources($projection) === []) {
            return false;
        }

        return app(DevelopmentProjectionOperationLock::class)->run(function () use ($step, $node, $projection, $paths): bool {
            $initial = in_array($step->status, ['intended', 'access-initializing'], true);
            if ($initial) {
                $step->update(['status' => 'access-initializing']);
            }
            $state = $this->checkpoint($step, $node, 'begin', $paths, ! $initial);
            if ($initial) {
                $step->update(['status' => 'access-recorded']);
            }
            if (! $state['ready']) {
                $ready = InstanceAppProjectionStep::query()->where('instance_app_projection_id', $projection->id)->whereKeyNot($step->id)
                    ->where('intent->phase', 'prepare')->whereIn('intent->resource', ['environment', 'serving'])
                    ->whereIn('status', ['access-ready', 'rendering', 'complete'])->exists();
                if (! $ready) {
                    $command = new DevelopmentCaddyAccessCommand()->command($this->forNode($node, $projection->id));
                    $script = $command->input ?? '';
                    $script = str_replace('find -P "$checkout" -type d -exec setfacl -m d:u:caddy:--- -- {} +',
                        'directories=(); while IFS= read -r -d "" directory; do directories+=("$directory"); done < <(find -P "$checkout" -type d -print0); setfacl -m d:u:caddy:--- -- "${directories[@]}"', $script);
                    foreach (['document_root', 'storage_target'] as $root) {
                        $script = str_replace('find -P "$'.$root.'" -type d -exec setfacl -m d:u:caddy:r-x -- {} +',
                            'directories=(); while IFS= read -r -d "" directory; do directories+=("$directory"); done < <(find -P "$'.$root.'" -type d -print0); setfacl -m d:u:caddy:r-x -- "${directories[@]}"', $script);
                    }
                    $guard = base64_encode(AppProjectionAccessProgram::script());
                    $receipt = escapeshellarg($step->receipt_id);
                    $prefix = 'access_program=$(printf %s '.escapeshellarg($guard).' | base64 --decode)'."\n".
                        'access_receipt='.$receipt."\n".<<<'BASH'
                        setfacl() { command sudo -n python3 -c "$access_program" "$access_receipt" "$@"; }
                        sudo() {
                            if [ "$1" = -n ] && [ "$2" = setfacl ]; then shift 2; setfacl "$@";
                            else command sudo "$@"; fi
                        }
                        BASH;
                    $this->ssh->execute($node, new RemoteCommand($command->arguments, $prefix."\n".$script), 'source-access', 'app-dev.source_access_failed');
                }
                $state = $this->checkpoint($step, $node, 'finish');
            }
            if (in_array($step->status, ['access-recorded', 'access-initializing', 'intended'], true)) {
                $step->update(['status' => 'access-ready']);
            }

            return ! $state['file_initialized'];
        });
    }

    /** @return array<string, string> */
    public static function binding(InstanceAppProjectionStep $step): array
    {
        return ['step_id' => $step->id, 'projection_id' => $step->instance_app_projection_id, 'receipt_id' => $step->receipt_id,
            'plan_digest' => $step->plan_digest, 'intent_digest' => AppProjectionIdentity::digest($step->intent)];
    }

    /** @param list<string> $paths
     * @return array{ready: bool, file_initialized: bool, aborted: bool}
     */
    private function checkpoint(InstanceAppProjectionStep $step, Node $node, string $action, array $paths = [], bool $recover = true): array
    {
        $result = $this->ssh->execute($node, new RemoteCommand(['sudo', 'python3', '-c', AppProjectionAccessProgram::script()],
            protectedInput: ProtectedInput::fromString(json_encode(['binding' => self::binding($step), 'action' => $action, 'paths' => $paths, 'recover' => $recover], JSON_THROW_ON_ERROR))),
            'serving-access-checkpoint', 'app.projection_receipt_conflict');
        $state = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($state) || ! is_bool($state['ready'] ?? null) || ! is_bool($state['file_initialized'] ?? null) || ! is_bool($state['aborted'] ?? null)) {
            throw new ResourceOperationException('app.projection_receipt_conflict', 'The access checkpoint is invalid.', 409);
        }

        return ['ready' => $state['ready'], 'file_initialized' => $state['file_initialized'], 'aborted' => $state['aborted']];
    }

    public function restoreBeforeFiles(InstanceAppProjectionStep $step, InstanceAppProjectionStep $source, Node $node): ?AppProjectionReceipt
    {
        $projection = InstanceAppProjection::query()->findOrFail($source->instance_app_projection_id);
        if (new CommittedAppServingView()->resources($projection) === []) {
            return null;
        }
        $state = $this->checkpoint($source, $node, 'begin');
        if (! $state['ready'] && ! $state['aborted']) {
            $this->prepare($source, $node);
            $state = $this->checkpoint($source, $node, 'begin');
        }
        if ($state['file_initialized']) {
            return null;
        }
        $this->checkpoint($source, $node, 'abort');

        $targets = $step->intent['targets'] ?? null;
        if (! is_array($targets) || array_any($targets, static fn (mixed $value): bool => ! is_string($value))) {
            throw new ResourceOperationException('app.projection_receipt_conflict', 'The access restoration targets are invalid.', 409);
        }
        $validatedTargets = [];
        foreach ($targets as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new ResourceOperationException('app.projection_receipt_conflict', 'The access restoration targets are invalid.', 409);
            }
            $validatedTargets[$key] = $value;
        }
        $fingerprint = AppProjectionIdentity::digest(['binding' => self::binding($step), 'source' => self::binding($source), 'before_files' => true]);

        return new AppProjectionReceipt($step->id, $step->instance_app_projection_id, $step->receipt_id, $step->plan_digest,
            AppProjectionIdentity::digest($step->intent), $fingerprint, [], true, $validatedTargets,
            ['access' => ['created' => true, 'protection_fingerprint' => AppProjectionIdentity::digest(['root_mode' => '0700', 'manifest_mode' => '0600']), 'result_fingerprint' => $fingerprint]]);
    }

    /**
     * The preparing owner establishes both roots. Other access callers leave its
     * checkout untouched while receipts own protections, including foreign ACL changes.
     * This is not a site publication view: Caddy and FPM still select one committed side.
     *
     * @return Collection<int, DevelopmentSite>
     */
    public function forNode(Node $node, ?string $preparingProjection = null): Collection
    {
        $sites = new DevelopmentSiteRepository()->forNode($node);
        $view = new CommittedAppServingView;
        foreach (InstanceAppProjection::query()->where('node_id', $node->id)->whereNotNull('active_instance_id')->get() as $owner) {
            $resources = $view->resources($owner);
            if ($resources === []) {
                continue;
            }
            $instance = Instance::query()->findOrFail($owner->instance_id);
            $placement = $owner->plan['placement'] ?? null;
            if (! $instance->placedOnAppDev() || ! is_array($placement) || ($placement['checkout_path'] ?? null) !== $instance->checkout_path
                || AppProjectionIdentity::digest($owner->plan) !== $owner->plan_digest) {
                throw new ResourceOperationException('app.projection_receipt_conflict', 'The committed access placement is invalid.', 409);
            }
            if ($owner->id !== $preparingProjection) {
                $sites = $sites->reject(fn (DevelopmentSite $site): bool => $site->checkoutPath === $instance->checkout_path
                    || str_starts_with($site->checkoutPath, $instance->checkout_path.'/'));

                continue;
            }
            $bases = [$instance->checkout_path];
            if ($instance->development_release_layout) {
                $release = $owner->plan['selected_release'] ?? null;
                if (! is_string($release) || ! str_starts_with($release, $instance->checkout_path.'/releases/')) {
                    throw new ResourceOperationException('app.projection_receipt_conflict', 'The selected access release is invalid.', 409);
                }
                $bases[] = $release;
            }
            foreach ($resources as $app => $resource) {
                foreach (['before', 'candidate'] as $side) {
                    $configuration = $view->configuration($owner, $side, $app);
                    if ($configuration === null || ! ProjectApps::isServing($configuration)) {
                        continue;
                    }
                    foreach ($bases as $base) {
                        $root = $configuration['web_root'] === null ? $configuration['path'] : ($configuration['path'] === '.' ? $configuration['web_root'] : $configuration['path'].'/'.$configuration['web_root']);
                        $sites->push(new DevelopmentSite(nodeId: $node->id, nodeAddress: $node->wireguard_ip ?? '', scope: 'projection-access-'.$owner->id.'-'.$app,
                            checkoutPath: $base, documentRoot: $root, phpVersion: null, domain: $resource['domain'], applicationPath: $configuration['path']));
                    }
                }
            }
        }

        return $sites;
    }
}
