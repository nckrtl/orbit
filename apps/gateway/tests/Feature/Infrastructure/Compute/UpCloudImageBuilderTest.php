<?php

declare(strict_types=1);

use App\Actions\Compute\ProvisionTaskSandboxAction;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxImageCacheSources;
use App\Domain\Compute\SandboxImageGuest;
use App\Domain\Compute\SandboxImageStatus;
use App\Domain\Compute\SandboxImageStep;
use App\Domain\Compute\SandboxSpec;
use App\Domain\Compute\SandboxState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\Compute\SandboxImageRetention;
use App\Infrastructure\Compute\UpCloudCloudInit;
use App\Infrastructure\Compute\UpCloudImageBuilder;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Activity;
use App\Models\Project;
use App\Models\SandboxImage;
use App\Models\Task;
use App\Models\TaskSandbox;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FakeSandboxImageGuest;
use Tests\Support\FakeSandboxModelProxy;
use Tests\Support\FakeUpCloud;

const IMAGE_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHdUmJNAeflz28V7EadKJL3DLqnMqS6JyEQJmpCPNG5T gateway';

/** @return array{FakeUpCloud, FakeSandboxImageGuest} */
function image_fakes(): array
{
    $path = tempnam(sys_get_temp_dir(), 'orbit-upcloud-token-');
    file_put_contents($path, "token: ucat_test_only\n");
    chmod($path, 0600);
    test()->beforeApplicationDestroyed(static function () use ($path): void {
        if (is_file($path)) {
            unlink($path);
        }
    });
    config(['compute.upcloud' => [
        'enabled' => true, 'token_file' => $path, 'max_vms' => 2, 'zone' => 'nl-ams1', 'base_image' => null,
        'gateway_address' => '1.1.1.1', 'wireguard_address' => '8.8.8.8', 'wireguard_port' => 51820,
        'image_build' => ['enabled' => true, 'time' => '03:00'],
    ]]);
    Http::preventStrayRequests();
    $upcloud = new FakeUpCloud()->install();
    $guest = new FakeSandboxImageGuest;
    app()->instance(SandboxImageGuest::class, $guest);
    app()->instance(SandboxImageCacheSources::class, new class implements SandboxImageCacheSources
    {
        public function collect(): array
        {
            return ['projects' => ['shop' => ['composer.json' => '{}', 'composer.lock' => '{}']], 'skipped' => ['shop' => ['npm' => 'missing']]];
        }
    });
    app()->instance(HostKeyScanner::class, new class implements HostKeyScanner
    {
        public function scan(string $host, int $port): HostKey
        {
            return new HostKey('ssh-ed25519', 'AAAAC3NzaC1lZDI1NTE5AAAAIFakePublicMaterial', 'SHA256:'.$host);
        }
    });
    app()->instance(SshKeyProvider::class, new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/private-key';
        }

        public function publicKey(): string
        {
            return IMAGE_KEY."\n";
        }
    });

    return [$upcloud, $guest];
}

function image_builder(): UpCloudImageBuilder
{
    return app(UpCloudImageBuilder::class);
}

/** Advances until the build leaves the given step, at most `$limit` ticks. */
function image_until(SandboxImage $image, SandboxImageStep $step, int $limit = 30): SandboxImage
{
    for ($tick = 0; $tick < $limit && $image->step !== $step; $tick++) {
        $image = image_builder()->advance($image);
    }
    expect($image->step)->toBe($step);

    return $image;
}

function image_published(FakeUpCloud $upcloud, ?CarbonInterface $at = null): SandboxImage
{
    $id = (string) Str::uuid();
    $image = SandboxImage::query()->create([
        'id' => $id, 'provider' => 'upcloud', 'zone' => 'nl-ams1', 'status' => SandboxImageStatus::Published, 'step' => SandboxImageStep::Done,
        'step_started_at' => now(), 'script_sha256' => str_repeat('a', 64), 'credential_fingerprint' => hash('sha256', 'ucat_test_only'),
        'published_at' => $at ?? now(), 'finished_at' => $at ?? now(),
    ]);
    $image->update(['template_id' => $upcloud->template('orbit-sandbox-base-'.$id)]);

    return $image;
}

describe('nightly base template build', function (): void {
    it('builds, smoke-tests, and publishes a template one step per tick', function (): void {
        [$upcloud, $guest] = image_fakes();
        $previous = image_published($upcloud, now()->subDay());
        $image = image_builder()->reserve();
        expect($upcloud->requests)->toBe([])
            ->and($image->status)->toBe(SandboxImageStatus::Building)
            ->and($image->script_sha256)->toBe(hash_file('sha256', resource_path(UpCloudImageBuilder::Script)));

        $image = image_builder()->advance($image);
        expect($image->step)->toBe(SandboxImageStep::AwaitBuild)->and($upcloud->count('POST server'))->toBe(1);
        $create = $upcloud->bodies['POST server']['server'];
        expect($create)->toMatchArray(['hostname' => 'orbit-image-build-'.$image->id, 'plan' => 'STARTER-2xCPU-2GB', 'zone' => 'nl-ams1', 'firewall' => 'on'])
            ->and($create['labels']['label'])->toBe([['key' => 'orbit-image-build', 'value' => $image->id]])
            ->and($create['storage_devices']['storage_device'][0])->toMatchArray(['storage' => SandboxSpec::Image, 'size' => 20, 'action' => 'clone']);
        $cloudInit = json_decode(substr($create['user_data'], strlen("#cloud-config\n")), true);
        expect($cloudInit['users'][0]['ssh_authorized_keys'])->toBe([IMAGE_KEY])->and($cloudInit)->not->toHaveKey('runcmd');

        $image = image_builder()->advance($image);
        expect($image->step)->toBe(SandboxImageStep::Install)
            ->and($upcloud->firewalls[$image->build_server_id])->toBe(app(UpCloudCloudInit::class)->imageBuildFirewall('1.1.1.1'))
            ->and($image->build_host_key['fingerprint'])->toBe('SHA256:'.$image->build_address);

        $image = image_builder()->advance($image);
        expect($guest->started['orbit-image-install'])->toBe(['script' => file_get_contents(resource_path(UpCloudImageBuilder::Script)), 'argument' => 'install'])
            ->and($image->step)->toBe(SandboxImageStep::Install);
        $image = image_until($image, SandboxImageStep::Warm);
        $guest->results = ['shop' => ['composer' => 'ok']];
        $image = image_until($image, SandboxImageStep::Clean);
        expect($guest->uploaded)->toBe(['shop' => ['composer.json' => '{}', 'composer.lock' => '{}']])
            ->and($guest->started['orbit-image-warm']['argument'])->toBe('warm')
            ->and($image->caches)->toBe(['projects' => ['shop' => ['npm' => 'missing', 'composer' => 'ok']]]);

        $image = image_until($image, SandboxImageStep::Templatize);
        expect($guest->cleans)->toBe(1)->and($upcloud->servers[$image->build_server_id]['state'])->toBe('stopped');
        $image = image_builder()->advance($image);
        expect($upcloud->bodies['POST storage/'.$image->build_disk_id.'/templatize'])->toBe(['storage' => ['title' => 'orbit-sandbox-base-'.$image->id]])
            ->and($image->step)->toBe(SandboxImageStep::Templatize);
        $image = image_until($image, SandboxImageStep::CreateSmoke);
        expect($upcloud->servers)->toBe([])->and($upcloud->storages[$image->template_id]['state'])->toBe('online');

        $image = image_builder()->advance($image);
        $smoke = $upcloud->bodies['POST server']['server'];
        expect($smoke)->toMatchArray(['hostname' => 'orbit-image-smoke-'.$image->id, 'plan' => 'STARTER-2xCPU-2GB'])
            ->and($smoke['storage_devices']['storage_device'][0])->toMatchArray(['storage' => $image->template_id, 'size' => 30])
            ->and($smoke['user_data'])->toBe(app(UpCloudCloudInit::class)->render(new SandboxSpec('nl-ams1', '1.1.1.1', '8.8.8.8', 51820, IMAGE_KEY, $image->template_id, 'starter-2x2')));
        $image = image_builder()->advance($image);
        expect($image->step)->toBe(SandboxImageStep::AwaitSmoke);
        $image = image_until($image, SandboxImageStep::Done);

        expect($image->status)->toBe(SandboxImageStatus::Published)->and($image->published_at)->not->toBeNull()
            ->and($upcloud->servers)->toBe([])->and($upcloud->count('POST server'))->toBe(2)
            ->and(SandboxImage::newestPublished('nl-ams1')->is($image))->toBeTrue()
            ->and($previous->fresh()->status)->toBe(SandboxImageStatus::Published);
    });

    it('starts each night once after the configured time, and not while disabled', function (): void {
        image_fakes();
        $this->travelTo(now()->setTime(2, 59));
        expect(image_builder()->due())->toBeFalse();
        $this->travelTo(now()->setTime(3, 0));
        expect(image_builder()->due())->toBeTrue();
        $this->artisan('orbit:sandbox-image-build')->assertSuccessful();
        expect(SandboxImage::query()->count())->toBe(1)->and(image_builder()->due())->toBeFalse();
        $this->travelTo(now()->addDay());
        config(['compute.upcloud.image_build.enabled' => false]);
        expect(image_builder()->due())->toBeFalse();
    });

    it('advances the running build instead of starting another one', function (): void {
        [$upcloud] = image_fakes();
        $this->artisan('orbit:sandbox-image-build --now')->assertSuccessful();
        $this->artisan('orbit:sandbox-image-build --now')->assertSuccessful();
        expect(SandboxImage::query()->count())->toBe(1)->and($upcloud->count('POST server'))->toBe(1)
            ->and(SandboxImage::query()->sole()->step)->toBe(SandboxImageStep::Install);
    });

    it('recovers a lost create response by the VM name and label without a second create', function (): void {
        [$upcloud] = image_fakes();
        $upcloud->lose = ['POST server'];
        $image = image_builder()->advance(image_builder()->reserve());
        expect($image->step)->toBe(SandboxImageStep::AwaitBuild)->and($image->build_server_id)->toBeNull()
            ->and($image->error_code)->toBe('compute.provider_unavailable');
        $image = image_builder()->advance($image);
        expect($image->build_server_id)->toBe(array_key_first($upcloud->servers))->and($image->step)->toBe(SandboxImageStep::Install)
            ->and($upcloud->count('POST server'))->toBe(1);
    });

    it('never creates a second VM when a lost create left none, and fails at the deadline', function (): void {
        [$upcloud] = image_fakes();
        $upcloud->dropCreates = true;
        $upcloud->lose = ['POST server'];
        $image = image_builder()->advance(image_builder()->reserve());
        $image = image_builder()->advance($image);
        expect($image->step)->toBe(SandboxImageStep::AwaitBuild)->and($image->status)->toBe(SandboxImageStatus::Building);
        $this->travel(21)->minutes();
        $image = image_builder()->advance($image);
        expect($image->status)->toBe(SandboxImageStatus::Failing)->and($image->error_code)->toBe('compute.image_step_timeout');
        $image = image_builder()->advance($image);
        expect($image->status)->toBe(SandboxImageStatus::Failed)->and($upcloud->count('POST server'))->toBe(1);
    });

    it('keeps the previous template, deletes the build VM, and alerts when the setup script fails', function (): void {
        [$upcloud, $guest] = image_fakes();
        $previous = image_published($upcloud, now()->subDay());
        $guest->outcomes['orbit-image-install'] = 'failed';
        $image = image_until(image_builder()->reserve(), SandboxImageStep::Cleanup);
        expect($image->status)->toBe(SandboxImageStatus::Failing)->and($image->failed_step)->toBe('install')
            ->and($image->error_code)->toBe('compute.image_unit_failed')->and($image->error_detail)->toContain('zfsutils-linux');
        $alert = Activity::query()->where('command', 'orbit:sandbox-image-build')->sole();
        expect($alert->status)->toBe('failed')->and($alert->error_code)->toBe('compute.image_build_failed')
            ->and($alert->properties['sandbox_image_id'])->toBe($image->id);

        $image = image_until($image, SandboxImageStep::Done);
        expect($image->status)->toBe(SandboxImageStatus::Failed)->and($upcloud->servers)->toBe([])
            ->and(SandboxImage::newestPublished('nl-ams1')->is($previous))->toBeTrue();
    });

    it('deletes the smoke VM, the build VM, and the unpublished template when the smoke test fails', function (): void {
        [$upcloud, $guest] = image_fakes();
        $previous = image_published($upcloud, now()->subDay());
        $guest->smoke = ['failed'];
        $image = image_until(image_builder()->reserve(), SandboxImageStep::Cleanup, 30);
        expect($image->failed_step)->toBe('await_smoke')->and($image->error_code)->toBe('compute.image_smoke_failed');
        $template = $image->template_id;
        $image = image_until($image, SandboxImageStep::Done);
        expect($image->status)->toBe(SandboxImageStatus::Failed)->and($upcloud->servers)->toBe([])
            ->and($upcloud->storages)->not->toHaveKey($template)->and($upcloud->storages)->toHaveKey($previous->template_id)
            ->and(SandboxImage::newestPublished('nl-ams1')->is($previous))->toBeTrue();
    });

    it('fails a step that runs past its deadline', function (): void {
        [, $guest] = image_fakes();
        $guest->cloudInitDone = false;
        $image = image_until(image_builder()->reserve(), SandboxImageStep::AwaitBuild);
        $image = image_builder()->advance($image);
        expect($image->status)->toBe(SandboxImageStatus::Building);
        $this->travel(21)->minutes();
        $image = image_builder()->advance($image);
        expect($image->status)->toBe(SandboxImageStatus::Failing)->and($image->failed_step)->toBe('await_build')
            ->and($image->error_code)->toBe('compute.image_step_timeout');
    });

    it('retries a provider failure until the deadline instead of failing at once', function (): void {
        [$upcloud] = image_fakes();
        $image = image_until(image_builder()->reserve(), SandboxImageStep::AwaitBuild);
        $upcloud->refuse = ['GET server/'.$image->build_server_id];
        $image = image_builder()->advance($image);
        expect($image->status)->toBe(SandboxImageStatus::Building)->and($image->error_code)->toBe('compute.provider_failed');
        $upcloud->refuse = [];
        $image = image_builder()->advance($image);
        expect($image->step)->toBe(SandboxImageStep::Install)->and($image->error_code)->toBeNull();
    });

    it('never runs the identity cleanup twice and fails when it did not confirm', function (): void {
        [, $guest] = image_fakes();
        $guest->cleanFailure = 'compute.image_guest_unreachable';
        $image = image_until(image_builder()->reserve(), SandboxImageStep::Clean);
        $image = image_builder()->advance($image);
        expect($image->status)->toBe(SandboxImageStatus::Building)->and($image->clean_attempted_at)->not->toBeNull();
        $image = image_builder()->advance($image);
        expect($image->status)->toBe(SandboxImageStatus::Failing)->and($image->error_code)->toBe('compute.image_clean_uncertain')
            ->and($guest->cleans)->toBe(1);
    });

    it('recovers a lost templatize response by the template title', function (): void {
        [$upcloud] = image_fakes();
        $image = image_until(image_builder()->reserve(), SandboxImageStep::Templatize);
        $upcloud->lose = ['POST storage/'.$image->build_disk_id.'/templatize'];
        $image = image_builder()->advance($image);
        expect($image->template_id)->toBeNull();
        $image = image_until($image, SandboxImageStep::DeleteBuild);
        expect($image->template_id)->not->toBeNull()->and($upcloud->sent('POST', 'storage'))->toHaveCount(1);
    });

    it('refuses a VM whose label does not name this build', function (): void {
        [$upcloud] = image_fakes();
        $image = image_until(image_builder()->reserve(), SandboxImageStep::AwaitBuild);
        $upcloud->servers[$image->build_server_id]['labels']['label'] = [['key' => 'orbit-image-build', 'value' => (string) Str::uuid()]];
        $image = image_builder()->advance($image);
        expect($image->status)->toBe(SandboxImageStatus::Failing)->and($image->error_code)->toBe('compute.ownership_mismatch');
    });
});

describe('base template retention', function (): void {
    it('keeps the newest two templates and any template a live sandbox records', function (): void {
        [$upcloud] = image_fakes();
        $oldest = image_published($upcloud, now()->subDays(4));
        $inUse = image_published($upcloud, now()->subDays(3));
        $previous = image_published($upcloud, now()->subDays(2));
        $newest = image_published($upcloud, now()->subDay());
        $destroyed = TaskSandbox::query()->create(['id' => $id = (string) Str::uuid(), 'provider' => 'upcloud', 'name' => 'orbit-sandbox-'.$id,
            'state' => SandboxState::Destroyed, 'desired_power' => 'destroyed', 'spec' => ['image' => $oldest->template_id]]);
        TaskSandbox::query()->create(['id' => $id = (string) Str::uuid(), 'provider' => 'upcloud', 'name' => 'orbit-sandbox-'.$id,
            'state' => SandboxState::Stopped, 'desired_power' => 'stopped', 'spec' => ['image' => $inUse->template_id]]);

        expect(app(SandboxImageRetention::class)->prune())->toBe([$oldest->template_id]);
        expect($oldest->fresh()->status)->toBe(SandboxImageStatus::Retired)->and($upcloud->storages)->not->toHaveKey($oldest->template_id)
            ->and($upcloud->storages)->toHaveKeys([$inUse->template_id, $previous->template_id, $newest->template_id])
            ->and($inUse->fresh()->status)->toBe(SandboxImageStatus::Published)->and($destroyed->exists)->toBeTrue();
        expect(app(SandboxImageRetention::class)->prune())->toBe([]);
    });

    it('refuses to delete a template whose title does not match the build', function (): void {
        [$upcloud] = image_fakes();
        $oldest = image_published($upcloud, now()->subDays(3));
        image_published($upcloud, now()->subDays(2));
        image_published($upcloud, now()->subDay());
        $upcloud->storages[$oldest->template_id]['title'] = 'someone-else';
        expect(fn () => app(SandboxImageRetention::class)->prune())->toThrow(ComputeException::class, 'does not match');
        expect($upcloud->sent('DELETE'))->toBe([])->and($oldest->fresh()->status)->toBe(SandboxImageStatus::Published);
    });
});

describe('reservations', function (): void {
    it('pin the newest published template over the configured one and keep it afterwards', function (): void {
        [$upcloud] = image_fakes();
        config(['compute.upcloud.base_image' => '0123abcd-0000-4000-8000-0000000000ba']);
        image_published($upcloud, now()->subDay());
        $newest = image_published($upcloud);
        (new FakeSandboxModelProxy)->install();
        $project = Project::query()->create(['name' => 'shop', 'slug' => 'shop', 'repository_url' => 'https://github.com/acme/shop.git', 'default_branch' => 'main']);
        $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Cloud group', 'brief' => 'One sandbox', 'task_compute' => 'vm',
            'status' => TaskGroupStatus::Todo, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi']);

        try {
            app(ProvisionTaskSandboxAction::class)->execute($group);
        } catch (ComputeException) {
        }
        $sandbox = TaskSandbox::query()->sole();
        expect($sandbox->spec['image'])->toBe($newest->template_id);
        image_published($upcloud, now()->addMinute());
        expect($sandbox->fresh()->spec['image'])->toBe($newest->template_id);
    });
});
