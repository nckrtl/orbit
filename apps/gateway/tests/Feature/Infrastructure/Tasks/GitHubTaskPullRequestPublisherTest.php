<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\GitHubTaskPullRequestPublisher;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\TaskGroup;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\LocalShellSshExecutor;

/** @param list<string> $arguments */
function publisher_git(string $directory, array $arguments): string
{
    return trim((new Process(['git', '-C', $directory, '-c', 'user.name=t', '-c', 'user.email=t@t', ...$arguments]))->mustRun()->getOutput());
}

function publisher_group(string $checkout, string $repository = 'git@github.com:acme/shop.git'): TaskGroup
{
    $app = OrbitApp::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => $repository, 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'publish-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.150', 'wireguard_ip' => '10.44.0.150', 'user' => 'orbit']);
    $instance = AppInstance::query()->create(['app_id' => $app->id, 'node_id' => $node->id, 'name' => 'task-7', 'checkout_path' => $checkout, 'branch' => 'task-7', 'status' => 'source_resolved']);
    $group = TaskGroup::query()->create(['app_id' => $app->id, 'title' => 'Export orders', 'brief' => 'Add an export.', 'status' => 'reviewing']);
    $group->taskable()->associate($instance);
    $group->save();

    return $group;
}

function publisher(SshExecutor $transport): GitHubTaskPullRequestPublisher
{
    app()->instance(AppDevSshExecutor::class, new AppDevSshExecutor(
        $transport,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/home/orbit/.orbit/ssh/id_ed25519';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAA';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/home/orbit/.orbit/ssh/known_hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    ));

    return app(GitHubTaskPullRequestPublisher::class);
}

function publisher_github(int $pullStatus = 201): void
{
    Http::fake([
        'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_publish'], 201),
        'https://api.github.com/repos/acme/shop/pulls?*' => Http::response([['html_url' => 'https://github.com/acme/shop/pull/12']]),
        'https://api.github.com/repos/acme/shop/pulls' => Http::response($pullStatus === 201 ? ['html_url' => 'https://github.com/acme/shop/pull/11'] : ['message' => 'A pull request already exists'], $pullStatus),
    ]);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/orbit-publish-*') ?: [] as $directory) {
        File::deleteDirectory($directory);
    }
});

it('pushes the task branch with a pull request token and opens the pull request', function (): void {
    $root = sys_get_temp_dir().'/orbit-publish-'.bin2hex(random_bytes(6));
    (new Process(['git', 'init', '--quiet', '--bare', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'task-7', $root.'/checkout']))->mustRun();
    publisher_git($root.'/checkout', ['commit', '--quiet', '--allow-empty', '-m', 'Models']);
    publisher_git($root.'/checkout', ['remote', 'add', 'origin', $root.'/origin.git']);
    GitHubTestSupport::storeApp();
    publisher_github();
    $group = publisher_group($root.'/checkout');

    $url = publisher(new LocalShellSshExecutor)->publish($group, "Adds the export.\n");

    expect($url)->toBe('https://github.com/acme/shop/pull/11')
        ->and(publisher_git($root.'/origin.git', ['rev-parse', 'refs/heads/task-'.$group->id]))->toBe(publisher_git($root.'/checkout', ['rev-parse', 'HEAD']));
    Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && $request->data() === ['repositories' => ['shop'], 'permissions' => ['contents' => 'write', 'pull_requests' => 'write']]);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'https://api.github.com/repos/acme/shop/pulls'
        && $request->data() === ['title' => 'Export orders', 'head' => 'task-'.TaskGroup::query()->sole()->id, 'base' => 'main', 'body' => "Adds the export.\n"]
        && $request->hasHeader('Authorization', 'Bearer ghs_publish'));
});

it('sends the token only on the standard input of the push', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github();
    $transport = new AppDevFakeSshExecutor;

    publisher($transport)->publish(publisher_group('/srv/orbit/apps/shop/task-7'), 'Body');

    $command = $transport->commands[0];
    expect($command->arguments)->toBe(['bash', '-seu', '--', '/srv/orbit/apps/shop/task-7', 'task-'.TaskGroup::query()->sole()->id])
        ->and($command->input)->toBeNull()
        ->and(stream_get_contents($command->protectedInput?->stream()))->toContain(base64_encode('x-access-token:ghs_publish'))
        ->and(stream_get_contents($command->protectedInput?->stream()))->toContain('git_read git -C "$checkout" push --quiet origin "HEAD:refs/heads/$branch"');
});

it('uses the open pull request that already has the task branch as its head', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github(422);

    expect(publisher(new AppDevFakeSshExecutor)->publish(publisher_group('/srv/orbit/apps/shop/task-7'), 'Body'))->toBe('https://github.com/acme/shop/pull/12');
});

it('refuses to publish without an App, for another host, or when the push fails', function (bool $app, string $repository, int $pushExit, string $message): void {
    if ($app) {
        GitHubTestSupport::storeApp();
    }
    publisher_github();
    $transport = new AppDevFakeSshExecutor([new CommandResult($pushExit, '', 'rejected', 1, false)]);

    expect(fn () => publisher($transport)->publish(publisher_group('/srv/orbit/apps/shop/task-7', $repository), 'Body'))
        ->toThrow(TaskPullRequestException::class, $message);
})->with([
    'no App' => [false, 'git@github.com:acme/shop.git', 0, 'The Gateway GitHub App is not registered.'],
    'another host' => [true, 'git@gitlab.com:acme/shop.git', 0, 'The Project repository is not on github.com.'],
    'rejected push' => [true, 'git@github.com:acme/shop.git', 1, 'The task branch could not be pushed.'],
]);
