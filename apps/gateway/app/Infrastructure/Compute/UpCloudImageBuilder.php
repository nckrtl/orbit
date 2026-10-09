<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxImageCacheSources;
use App\Domain\Compute\SandboxImageGuest;
use App\Domain\Compute\SandboxImageStatus;
use App\Domain\Compute\SandboxImageStep;
use App\Domain\Compute\SandboxSpec;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\SandboxImage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds the UpCloud sandbox base template (ADR 0204) as a step machine. Each call to `advance`
 * runs at most one step and returns; long work on the build VM runs in a systemd unit that a later
 * tick reads. Creation follows the compute driver's rules: the attempt is recorded before the request,
 * a lost response is recovered by the VM's unique name and label, and no second create is sent.
 *
 * A failed step records the failure, raises an alert, and moves to cleanup. Cleanup deletes the smoke
 * VM, the build VM, and an unpublished template, retrying each tick until all are gone. The previous
 * published template stays in use.
 */
final readonly class UpCloudImageBuilder
{
    public const string Script = 'compute/upcloud-base-image.sh';

    public const string WarmScript = 'compute/upcloud-warm-caches.sh';

    public const string BuildPlan = 'STARTER-2xCPU-2GB';

    public const int BuildDiskGb = 20;

    /** Errors that no retry can fix. Others are retried on the next tick until the step deadline. */
    private const array Permanent = [
        'compute.ownership_mismatch', 'compute.credential_changed', 'compute.credential_unavailable', 'compute.invalid_spec',
        'compute.image_unit_failed', 'compute.image_clean_failed', 'compute.image_clean_uncertain', 'compute.image_smoke_failed',
        'compute.image_template_failed', 'compute.image_vm_missing',
    ];

    public function __construct(private UpCloudClient $client, private UpCloudCloudInit $cloudInit, private SandboxImageGuest $guest,
        private HostKeyScanner $scanner, private SshKeyProvider $keys, private SandboxImageCacheSources $sources, private SandboxImageAlerts $alerts) {}

    /** The build that is running or cleaning up, if any. */
    public function active(): ?SandboxImage
    {
        return SandboxImage::query()->where('provider', 'upcloud')
            ->whereIn('status', [SandboxImageStatus::Building->value, SandboxImageStatus::Failing->value])
            ->oldest('created_at')->first();
    }

    /** True when the nightly build is enabled, its time today has passed, and no build started since then. */
    public function due(?Carbon $now = null): bool
    {
        $now ??= now();
        $time = config('compute.upcloud.image_build.time');
        if (! config('compute.upcloud.image_build.enabled', false) || ! is_string($time) || preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/D', $time) !== 1) {
            return false;
        }
        $start = $now->copy()->setTimeFromTimeString($time);

        return $now->greaterThanOrEqualTo($start)
            && ! SandboxImage::query()->where('provider', 'upcloud')->where('created_at', '>=', $start)->exists();
    }

    /** Records a new build before any provider call. */
    public function reserve(): SandboxImage
    {
        $zone = config('compute.upcloud.zone');
        $this->smokeSpec(is_string($zone) ? $zone : '', SandboxSpec::Image);
        $script = file_get_contents(resource_path(self::Script));
        if (! is_string($script) || ! is_string(file_get_contents(resource_path(self::WarmScript)))) {
            throw new ComputeException('compute.invalid_spec', 'The base template scripts are missing.');
        }

        return SandboxImage::query()->create([
            'id' => (string) Str::uuid(), 'provider' => 'upcloud', 'zone' => $zone,
            'status' => SandboxImageStatus::Building, 'step' => SandboxImageStep::CreateBuild, 'step_started_at' => now(),
            'script_sha256' => hash('sha256', $script), 'credential_fingerprint' => $this->client->credentialFingerprint(),
        ]);
    }

    public function advance(SandboxImage $image): SandboxImage
    {
        $image->refresh();
        if (! in_array($image->status, [SandboxImageStatus::Building, SandboxImageStatus::Failing], true)) {
            return $image;
        }
        $step = $image->step;
        try {
            $this->run($image);
            if ($image->step === $step && $this->overdue($image)) {
                $this->fail($image, 'compute.image_step_timeout', 'The '.$step->value.' step did not finish in time.');
            }
        } catch (ComputeException $exception) {
            if ($image->status === SandboxImageStatus::Failing) {
                $image->update(['error_detail' => $exception->errorCode.': '.$exception->getMessage()]);
            } elseif (in_array($exception->errorCode, self::Permanent, true) || $this->overdue($image)) {
                $this->fail($image, $exception->errorCode, $exception->getMessage());
            } else {
                $image->update(['error_code' => $exception->errorCode]);
            }
        }

        return $image->refresh();
    }

    private function run(SandboxImage $image): void
    {
        match ($image->step) {
            SandboxImageStep::CreateBuild => $this->create($image, 'build'),
            SandboxImageStep::AwaitBuild => $this->awaitBuild($image),
            SandboxImageStep::Install => $this->unit($image, 'orbit-image-install', self::Script, 'install', SandboxImageStep::Warm),
            SandboxImageStep::Warm => $this->unit($image, 'orbit-image-warm', self::WarmScript, 'warm', SandboxImageStep::Clean),
            SandboxImageStep::Clean => $this->clean($image),
            SandboxImageStep::StopBuild => $this->stopBuild($image),
            SandboxImageStep::Templatize => $this->templatize($image),
            SandboxImageStep::DeleteBuild => $this->removed($image, 'build') && $this->next($image, SandboxImageStep::CreateSmoke),
            SandboxImageStep::CreateSmoke => $this->create($image, 'smoke'),
            SandboxImageStep::AwaitSmoke => $this->awaitSmoke($image),
            SandboxImageStep::DeleteSmoke => $this->removed($image, 'smoke') && $this->next($image, SandboxImageStep::Publish),
            SandboxImageStep::Publish => $image->update(['status' => SandboxImageStatus::Published, 'step' => SandboxImageStep::Done,
                'step_started_at' => now(), 'published_at' => now(), 'finished_at' => now(), 'error_code' => null]),
            SandboxImageStep::Cleanup => $this->cleanup($image),
            SandboxImageStep::Done => null,
        };
    }

    private function create(SandboxImage $image, string $role): void
    {
        $await = $role === 'build' ? SandboxImageStep::AwaitBuild : SandboxImageStep::AwaitSmoke;
        if ($image->getAttribute($role.'_create_attempted_at') !== null) {
            $this->next($image, $await);

            return;
        }
        $body = $role === 'build' ? $this->buildRequest($image) : $this->smokeRequest($image);
        // Record the attempt and the next step first: a lost response is then recovered by name, never re-sent.
        $image->update([$role.'_create_attempted_at' => now(), 'step' => $await, 'step_started_at' => now(), 'error_code' => null]);
        $id = data_get($this->request($image, 'POST', 'server', $body), 'server.uuid');
        if (is_string($id) && Str::isUuid($id)) {
            $image->update([$role.'_server_id' => $id]);
        }
    }

    private function awaitBuild(SandboxImage $image): void
    {
        $server = $this->server($image, 'build');
        if ($server === null || ! $this->started($server)) {
            return;
        }
        $this->ensureFirewall($image, 'build', $this->cloudInit->imageBuildFirewall($this->gatewayAddress()));
        if ($this->guest->cloudInitDone(...$this->vm($image, 'build'))) {
            $this->next($image, SandboxImageStep::Install);
        }
    }

    private function unit(SandboxImage $image, string $unit, string $script, string $argument, SandboxImageStep $next): void
    {
        [$address, $key] = $this->vm($image, 'build');
        $state = $this->guest->unitState($address, $key, $unit);
        if ($state === 'missing') {
            if ($argument === 'warm') {
                $sources = $this->sources->collect();
                $this->guest->uploadCaches($address, $key, $sources['projects']);
                $image->update(['caches' => ['projects' => $sources['skipped']]]);
            }
            $contents = file_get_contents(resource_path($script));
            if (! is_string($contents)) {
                throw new ComputeException('compute.invalid_spec', 'The base template script is missing.');
            }
            $this->guest->startUnit($address, $key, $unit, $contents, $argument);
        } elseif ($state === 'failed') {
            try {
                $image->update(['error_detail' => mb_substr($this->guest->unitLog($address, $key, $unit), -8000)]);
            } catch (ComputeException) {
            }
            throw new ComputeException('compute.image_unit_failed', 'The '.$argument.' step failed on the build VM.');
        } elseif ($state === 'succeeded') {
            if ($argument === 'warm') {
                $projects = [];
                foreach ((array) ($image->caches['projects'] ?? []) as $slug => $tools) {
                    $projects[(string) $slug] = is_array($tools) ? $tools : [];
                }
                foreach ($this->guest->cacheResults($address, $key) as $slug => $tools) {
                    $projects[$slug] = [...$projects[$slug] ?? [], ...$tools];
                }
                ksort($projects);
                $image->update(['caches' => ['projects' => $projects]]);
            }
            $this->next($image, $next);
        }
    }

    private function clean(SandboxImage $image): void
    {
        if ($image->clean_attempted_at !== null) {
            throw new ComputeException('compute.image_clean_uncertain', 'An earlier identity cleanup did not confirm, and the cleaned VM no longer admits the Gateway.');
        }
        $script = file_get_contents(resource_path(self::Script));
        if (! is_string($script) || ! hash_equals($image->script_sha256, hash('sha256', $script))) {
            throw new ComputeException('compute.image_clean_failed', 'The setup script changed during the build.');
        }
        [$address, $key] = $this->vm($image, 'build');
        $image->update(['clean_attempted_at' => now()]);
        $this->guest->clean($address, $key, $script);
        $this->next($image, SandboxImageStep::StopBuild);
    }

    private function stopBuild(SandboxImage $image): void
    {
        $server = $this->server($image, 'build') ?? throw new ComputeException('compute.image_vm_missing', 'The build VM disappeared before templating.');
        if (($server['state'] ?? null) === 'started') {
            $this->request($image, 'POST', 'server/'.$this->serverId($image, 'build').'/stop', ['stop_server' => ['stop_type' => 'soft', 'timeout' => '60']]);
        } elseif (($server['state'] ?? null) === 'stopped') {
            $this->next($image, SandboxImageStep::Templatize);
        }
    }

    private function templatize(SandboxImage $image): void
    {
        if ($image->template_id === null && $image->templatize_attempted_at === null) {
            if ($image->build_disk_id === null) {
                throw new ComputeException('compute.image_vm_missing', 'The build disk is unknown.');
            }
            $image->update(['templatize_attempted_at' => now()]);
            $id = data_get($this->request($image, 'POST', 'storage/'.$image->build_disk_id.'/templatize', ['storage' => ['title' => $image->templateTitle()]]), 'storage.uuid');
            if (is_string($id) && Str::isUuid($id)) {
                $image->update(['template_id' => $id]);
            }
        }
        $template = $this->template($image);
        if ($template === null) {
            return;
        }
        $state = $template['state'] ?? null;
        if ($state === 'online') {
            $this->next($image, SandboxImageStep::DeleteBuild);
        } elseif ($state !== 'maintenance') {
            throw new ComputeException('compute.image_template_failed', 'UpCloud reports the template in state '.(is_string($state) ? $state : 'unknown').'.');
        }
    }

    private function awaitSmoke(SandboxImage $image): void
    {
        $server = $this->server($image, 'smoke');
        if ($server === null || ! $this->started($server)) {
            return;
        }
        $this->ensureFirewall($image, 'smoke', $this->cloudInit->firewall($this->smokeSpec($image->zone, (string) $image->template_id)));
        $state = $this->guest->smokeState(...$this->vm($image, 'smoke'));
        if ($state === 'failed') {
            throw new ComputeException('compute.image_smoke_failed', 'The smoke VM did not finish cloud-init with the ZFS checkout dataset mounted.');
        }
        if ($state === 'passed') {
            $this->next($image, SandboxImageStep::DeleteSmoke);
        }
    }

    private function cleanup(SandboxImage $image): void
    {
        if ($this->removed($image, 'smoke') && $this->removed($image, 'build') && $this->templateRemoved($image)) {
            $image->update(['status' => SandboxImageStatus::Failed, 'step' => SandboxImageStep::Done, 'step_started_at' => now(), 'finished_at' => now()]);
        }
    }

    private function fail(SandboxImage $image, string $code, string $message): void
    {
        $image->update([
            'status' => SandboxImageStatus::Failing, 'failed_step' => $image->step->value, 'step' => SandboxImageStep::Cleanup,
            'step_started_at' => now(), 'error_code' => $code, 'error_detail' => $image->error_detail ?? $message,
        ]);
        $this->alerts->failed($image, $message);
    }

    private function next(SandboxImage $image, SandboxImageStep $step): bool
    {
        $image->update(['step' => $step, 'step_started_at' => now(), 'error_code' => null]);

        return true;
    }

    private function overdue(SandboxImage $image): bool
    {
        $minutes = $image->step->deadlineMinutes();

        return $minutes !== null && $image->step_started_at->copy()->addMinutes($minutes)->isPast();
    }

    /** True once the VM and its disk are gone. Each call sends at most one mutation. */
    private function removed(SandboxImage $image, string $role): bool
    {
        if ($image->getAttribute($role.'_create_attempted_at') === null) {
            return true;
        }
        $server = $this->server($image, $role);
        if ($server !== null) {
            $id = $this->serverId($image, $role);
            $state = $server['state'] ?? null;
            if ($state === 'started') {
                $this->request($image, 'POST', 'server/'.$id.'/stop', ['stop_server' => ['stop_type' => 'hard']]);
            } elseif ($state === 'stopped') {
                $this->request($image, 'DELETE', 'server/'.$id.'/?storages=1');
            }

            return false;
        }
        $disk = $image->getAttribute($role.'_disk_id');
        if (! is_string($disk)) {
            return true;
        }
        $storage = data_get($this->request($image, 'GET', 'storage/'.$disk, allowMissing: true), 'storage');
        if ($storage === null) {
            return true;
        }
        if (! is_array($storage) || ($storage['uuid'] ?? null) !== $disk || ($storage['title'] ?? null) !== $image->name($role).'-disk'
            || data_get($storage, 'servers.server') !== []) {
            throw $this->ownership();
        }
        $this->request($image, 'DELETE', 'storage/'.$disk);

        return false;
    }

    private function templateRemoved(SandboxImage $image): bool
    {
        if ($image->templatize_attempted_at === null) {
            return true;
        }
        $template = $this->template($image);
        if ($template === null) {
            return true;
        }
        if (($template['state'] ?? null) !== 'maintenance') {
            $this->request($image, 'DELETE', 'storage/'.$image->template_id);
        }

        return false;
    }

    /**
     * The template this build created, found by its recorded UUID or, after a lost response, by its unique title.
     *
     * @return array<string, mixed>|null
     */
    private function template(SandboxImage $image): ?array
    {
        if ($image->template_id === null) {
            $storages = data_get($this->request($image, 'GET', 'storage/template'), 'storages.storage');
            if (! is_array($storages)) {
                throw new ComputeException('compute.provider_invalid_response', 'UpCloud returned an invalid template list.');
            }
            $matches = array_values(array_filter($storages, fn (mixed $storage): bool => is_array($storage) && ($storage['title'] ?? null) === $image->templateTitle()));
            if ($matches === []) {
                return null;
            }
            if (count($matches) !== 1 || ! is_string($matches[0]['uuid'] ?? null) || ! Str::isUuid($matches[0]['uuid'])) {
                throw $this->ownership();
            }
            $image->update(['template_id' => $matches[0]['uuid']]);
        }
        $storage = data_get($this->request($image, 'GET', 'storage/'.$image->template_id, allowMissing: true), 'storage');
        if ($storage === null) {
            return null;
        }
        if (! is_array($storage) || ($storage['uuid'] ?? null) !== $image->template_id || ($storage['title'] ?? null) !== $image->templateTitle()
            || ($storage['type'] ?? null) !== 'template') {
            throw $this->ownership();
        }

        return $storage;
    }

    /**
     * The owned VM, found by its recorded UUID or by its unique name after a lost create response.
     *
     * @return array<string, mixed>|null
     */
    private function server(SandboxImage $image, string $role): ?array
    {
        if ($image->getAttribute($role.'_create_attempted_at') === null) {
            return null;
        }
        $name = $image->name($role);
        $id = $image->getAttribute($role.'_server_id');
        if (! is_string($id)) {
            $servers = data_get($this->request($image, 'GET', 'server'), 'servers.server');
            if (! is_array($servers) || count($servers) > 5000) {
                throw new ComputeException('compute.provider_invalid_response', 'UpCloud returned an invalid VM list.');
            }
            $matches = array_values(array_filter($servers, fn (mixed $server): bool => is_array($server)
                && ($server['hostname'] ?? null) === $name && ($server['title'] ?? null) === $name));
            if ($matches === []) {
                return null;
            }
            if (count($matches) !== 1 || ! is_string($matches[0]['uuid'] ?? null) || ! Str::isUuid($matches[0]['uuid'])) {
                throw $this->ownership();
            }
            $id = $matches[0]['uuid'];
        }
        $server = data_get($this->request($image, 'GET', 'server/'.$id, allowMissing: true), 'server');
        if ($server === null) {
            return null;
        }
        $labels = is_array($server) ? data_get($server, 'labels.label') : null;
        $devices = is_array($server) ? data_get($server, 'storage_devices.storage_device') : null;
        $recordedDisk = $image->getAttribute($role.'_disk_id');
        if (! is_array($server) || ($server['uuid'] ?? null) !== $id || ($server['hostname'] ?? null) !== $name || ($server['title'] ?? null) !== $name
            || ! is_array($labels) || ! in_array(['key' => 'orbit-image-'.$role, 'value' => $image->id], $labels, true)
            || ! is_array($devices) || count($devices) !== 1 || ! is_array($devices[0] ?? null) || ! is_string($devices[0]['storage'] ?? null)
            || ! Str::isUuid($devices[0]['storage']) || ($devices[0]['storage_title'] ?? null) !== $name.'-disk' || ($devices[0]['type'] ?? null) !== 'disk'
            || ($recordedDisk !== null && $recordedDisk !== $devices[0]['storage'])) {
            throw $this->ownership();
        }
        $attributes = [$role.'_server_id' => $id, $role.'_disk_id' => $devices[0]['storage']];
        foreach ((array) data_get($server, 'ip_addresses.ip_address', []) as $address) {
            if (is_array($address) && ($address['access'] ?? null) === 'public' && ($address['family'] ?? null) === 'IPv4'
                && is_string($address['address'] ?? null) && filter_var($address['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $attributes[$role.'_address'] = $address['address'];
            }
        }
        $image->update($attributes);

        /** @var array<string, mixed> $server */
        return $server;
    }

    /** @param array<string, mixed> $server */
    private function started(array $server): bool
    {
        if (($server['state'] ?? null) !== 'started') {
            return false;
        }
        if (($server['firewall'] ?? null) !== 'on') {
            throw new ComputeException('compute.firewall_failed', 'The UpCloud firewall of the image VM is not enabled.');
        }

        return true;
    }

    /** @param list<array<string, string>> $expected */
    private function ensureFirewall(SandboxImage $image, string $role, array $expected): void
    {
        $path = 'server/'.$this->serverId($image, $role).'/firewall_rule';
        $rules = data_get($this->request($image, 'GET', $path), 'firewall_rules.firewall_rule');
        if ($this->cloudInit->sameFirewall($rules, $expected)) {
            return;
        }
        $this->request($image, 'PUT', $path, ['firewall_rules' => ['firewall_rule' => $expected]]);
        $rules = data_get($this->request($image, 'GET', $path), 'firewall_rules.firewall_rule');
        if (! $this->cloudInit->sameFirewall($rules, $expected)) {
            throw new ComputeException('compute.firewall_failed', 'The UpCloud firewall of the image VM did not converge.');
        }
    }

    /**
     * The VM's address and the SSH host key recorded on first contact.
     *
     * @return array{string, HostKey}
     */
    private function vm(SandboxImage $image, string $role): array
    {
        $address = $image->getAttribute($role.'_address');
        if (! is_string($address)) {
            throw new ComputeException('compute.image_vm_missing', 'The image VM has no public address.');
        }
        $recorded = $image->getAttribute($role.'_host_key');
        if (! is_array($recorded)) {
            try {
                $key = $this->scanner->scan($address, 22);
            } catch (Throwable) {
                throw new ComputeException('compute.image_guest_unreachable', 'The image VM SSH host key is not available yet.');
            }
            $image->update([$role.'_host_key' => ['type' => $key->type, 'value' => $key->value, 'fingerprint' => $key->fingerprint]]);

            return [$address, $key];
        }

        if (! is_string($recorded['type'] ?? null) || ! is_string($recorded['value'] ?? null) || ! is_string($recorded['fingerprint'] ?? null)) {
            throw $this->ownership();
        }

        return [$address, new HostKey($recorded['type'], $recorded['value'], $recorded['fingerprint'])];
    }

    private function serverId(SandboxImage $image, string $role): string
    {
        $id = $image->getAttribute($role.'_server_id');
        if (! is_string($id)) {
            throw new ComputeException('compute.image_vm_missing', 'The image VM is unknown.');
        }

        return $id;
    }

    /** @return array<string, mixed> */
    private function buildRequest(SandboxImage $image): array
    {
        $name = $image->name('build');

        return ['server' => [
            'hostname' => $name, 'title' => $name, 'zone' => $image->zone, 'plan' => self::BuildPlan,
            'metadata' => 'yes', 'firewall' => 'on', 'remote_access_enabled' => 'no',
            'labels' => ['label' => [['key' => 'orbit-image-build', 'value' => $image->id]]],
            'networking' => ['interfaces' => ['interface' => [['type' => 'public', 'ip_addresses' => ['ip_address' => [['family' => 'IPv4']]]]]]],
            'storage_devices' => ['storage_device' => [[
                'action' => 'clone', 'storage' => SandboxSpec::Image, 'size' => self::BuildDiskGb, 'tier' => 'standard', 'title' => $name.'-disk',
            ]]],
            'user_data' => $this->cloudInit->renderImageBuild($this->publicKey()),
        ]];
    }

    /** @return array<string, mixed> */
    private function smokeRequest(SandboxImage $image): array
    {
        if ($image->template_id === null) {
            throw new ComputeException('compute.image_vm_missing', 'The template is unknown.');
        }
        $name = $image->name('smoke');
        $spec = $this->smokeSpec($image->zone, $image->template_id);

        return ['server' => [
            'hostname' => $name, 'title' => $name, 'zone' => $spec->zone, 'plan' => $spec->plan(),
            'metadata' => 'yes', 'firewall' => 'on', 'remote_access_enabled' => 'no',
            'labels' => ['label' => [['key' => 'orbit-image-smoke', 'value' => $image->id]]],
            'networking' => ['interfaces' => ['interface' => [['type' => 'public', 'ip_addresses' => ['ip_address' => [['family' => 'IPv4']]]]]]],
            'storage_devices' => ['storage_device' => [[
                'action' => 'clone', 'storage' => $spec->image, 'size' => $spec->diskGb(), 'tier' => 'standard', 'title' => $name.'-disk',
            ]]],
            'user_data' => $this->cloudInit->render($spec),
        ]];
    }

    /** The spec a claim would get from this template, so the smoke VM runs the real cloud-init. */
    private function smokeSpec(string $zone, string $image): SandboxSpec
    {
        return SandboxSpec::fromArray([
            'image' => $image, 'size' => SandboxSpec::DefaultSize, 'zone' => $zone,
            'gateway_address' => config('compute.upcloud.gateway_address'), 'wireguard_address' => config('compute.upcloud.wireguard_address'),
            'wireguard_port' => config('compute.upcloud.wireguard_port'), 'public_key' => $this->publicKey(),
        ]);
    }

    private function gatewayAddress(): string
    {
        $address = config('compute.upcloud.gateway_address');
        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new ComputeException('compute.invalid_spec', 'The Gateway address is invalid.');
        }

        return $address;
    }

    private function publicKey(): string
    {
        return trim($this->keys->publicKey());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function request(SandboxImage $image, string $method, string $path, array $data = [], bool $allowMissing = false): ?array
    {
        return $this->client->request($method, $path, $image->credential_fingerprint, $data, $allowMissing);
    }

    private function ownership(): ComputeException
    {
        return new ComputeException('compute.ownership_mismatch', 'The UpCloud resource does not match the recorded image build ownership.');
    }
}
