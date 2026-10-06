<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\GitHubTaskPullRequestPublisher;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\TestOrbitHome;

/** @param list<string> $arguments */
function publisher_git(string $directory, array $arguments): string
{
    return trim((new Process(['git', '-C', $directory, '-c', 'user.name=t', '-c', 'user.email=t@t', ...$arguments]))->mustRun()->getOutput());
}

function publisher_group(string $checkout, string $repository = 'git@github.com:acme/shop.git'): Task
{
    $project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => $repository, 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'publish-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.150', 'wireguard_ip' => '10.44.0.150', 'user' => 'orbit']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-7', 'checkout_path' => $checkout, 'branch' => 'task-7', 'status' => 'source_resolved']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Export orders', 'brief' => 'Add an export.', 'status' => 'reviewing']);
    $group->taskable()->associate($instance);
    $group->save();

    return $group;
}

function publisher(SshExecutor $transport): GitHubTaskPullRequestPublisher
{
    app()->instance(DevelopmentSshExecutor::class, new DevelopmentSshExecutor(
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

function publisher_github(int $pullStatus = 201, int $reviewersStatus = 201): void
{
    $reviewers = $reviewersStatus === 201
        ? Http::response(['requested_reviewers' => [['login' => 'reviewbot']]], 201)
        : Http::response(['message' => 'Review cannot be requested from pull request author.'], $reviewersStatus);
    Http::fake([
        'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_publish'], 201),
        'https://api.github.com/repos/acme/shop/pulls/11/requested_reviewers' => $reviewers,
        'https://api.github.com/repos/acme/shop/pulls/12/requested_reviewers' => $reviewers,
        'https://api.github.com/repos/acme/shop/pulls?*' => Http::response([[
            'html_url' => 'https://github.com/acme/shop/pull/12',
            'number' => 12,
            'user' => ['login' => 'orbit-bot'],
        ]]),
        'https://api.github.com/repos/acme/shop/pulls' => Http::response($pullStatus === 201 ? [
            'html_url' => 'https://github.com/acme/shop/pull/11',
            'number' => 11,
            'user' => ['login' => 'orbit-bot'],
        ] : ['message' => 'A pull request already exists'], $pullStatus),
    ]);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['orbit.tasks.review_request_logins' => []]);
});

afterEach(function (): void {
    TestOrbitHome::clearScratch();
});

it('pushes the task branch with a pull request token and opens the pull request', function (): void {
    $root = TestOrbitHome::scratch('orbit-publish');
    (new Process(['git', 'init', '--quiet', '--bare', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'task-7', $root.'/checkout']))->mustRun();
    publisher_git($root.'/checkout', ['commit', '--quiet', '--allow-empty', '-m', 'Models']);
    publisher_git($root.'/checkout', ['remote', 'add', 'origin', $root.'/origin.git']);
    GitHubTestSupport::storeApp();
    publisher_github();
    $group = publisher_group($root.'/checkout');
    $commit = publisher_git($root.'/checkout', ['rev-parse', 'HEAD']);

    $url = publisher(new LocalShellSshExecutor)->publish($group, "Adds the export.\n", $commit);

    expect($url)->toBe('https://github.com/acme/shop/pull/11')
        ->and(publisher_git($root.'/origin.git', ['rev-parse', 'refs/heads/task-'.$group->id]))->toBe($commit);
    Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && $request->data() === ['repositories' => ['shop'], 'permissions' => ['contents' => 'write', 'pull_requests' => 'write', 'workflows' => 'write']]);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'https://api.github.com/repos/acme/shop/pulls'
        && $request->data() === ['title' => 'Export orders', 'head' => 'task-'.Task::topLevel()->sole()->id, 'base' => 'main', 'body' => "Adds the export.\n"]
        && $request->hasHeader('Authorization', 'Bearer ghs_publish'));
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/requested_reviewers'));
});

it('pushes the task branch without opening a pull request', function (): void {
    $root = TestOrbitHome::scratch('orbit-push');
    (new Process(['git', 'init', '--quiet', '--bare', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'task-7', $root.'/checkout']))->mustRun();
    publisher_git($root.'/checkout', ['commit', '--quiet', '--allow-empty', '-m', 'Approved subtask']);
    $commit = publisher_git($root.'/checkout', ['rev-parse', 'HEAD']);
    publisher_git($root.'/checkout', ['commit', '--quiet', '--allow-empty', '-m', 'Later HEAD']);
    publisher_git($root.'/checkout', ['remote', 'add', 'origin', $root.'/origin.git']);
    GitHubTestSupport::storeApp();
    publisher_github();
    $group = publisher_group($root.'/checkout');

    publisher(new LocalShellSshExecutor)->push($group, $commit);

    expect(publisher_git($root.'/origin.git', ['rev-parse', 'refs/heads/task-'.$group->id]))->toBe($commit)
        ->and(publisher_git($root.'/checkout', ['rev-parse', 'HEAD']))->not->toBe($commit);
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/pulls'));
});

it('sends the token only on the standard input of the push', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github();
    $transport = new AppDevFakeSshExecutor;

    $commit = str_repeat('a', 40);
    publisher($transport)->publish(publisher_group('/srv/orbit/apps/shop/task-7'), 'Body', $commit);

    $command = $transport->commands[0];
    expect($command->arguments)->toBe(['bash', '-seu', '--', '/srv/orbit/apps/shop/task-7', 'task-'.Task::topLevel()->sole()->id, $commit])
        ->and($command->input)->toBeNull()
        ->and(stream_get_contents($command->protectedInput?->stream()))->toContain(base64_encode('x-access-token:ghs_publish'))
        ->and(stream_get_contents($command->protectedInput?->stream()))->toContain('git_read git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" push --quiet origin "$commit:refs/heads/$branch"')
        ->and(stream_get_contents($command->protectedInput?->stream()))->not->toContain('HEAD:refs/heads');
});

it('refuses a push that does not name a commit sha', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github();
    $transport = new AppDevFakeSshExecutor;

    expect(fn () => publisher($transport)->push(publisher_group('/srv/orbit/apps/shop/task-7'), 'HEAD'))
        ->toThrow(TaskPullRequestException::class, 'The approved commit is not a Git SHA.');
    expect($transport->commands)->toBe([]);
});

it('uses the open pull request that already has the task branch as its head', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github(422);

    expect(publisher(new AppDevFakeSshExecutor)->publish(publisher_group('/srv/orbit/apps/shop/task-7'), 'Body', str_repeat('a', 40)))->toBe('https://github.com/acme/shop/pull/12');
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/requested_reviewers'));
});

it('requests the configured reviewers after opening a pull request', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github();
    config(['orbit.tasks.review_request_logins' => ['reviewbot', 'orbit-bot']]);

    $url = publisher(new AppDevFakeSshExecutor)->publish(publisher_group('/srv/orbit/apps/shop/task-7'), 'Body', str_repeat('a', 40));

    expect($url)->toBe('https://github.com/acme/shop/pull/11');
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.github.com/repos/acme/shop/pulls/11/requested_reviewers'
        && $request->data() === ['reviewers' => ['reviewbot']]
        && $request->hasHeader('Authorization', 'Bearer ghs_publish'));
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'https://api.github.com/repos/acme/shop/pulls'
        && $request->data() === ['title' => 'Export orders', 'head' => 'task-'.Task::topLevel()->sole()->id, 'base' => 'main', 'body' => 'Body']);
});

it('does not send a reviewers request when review request logins are unset', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github();
    config(['orbit.tasks.review_request_logins' => []]);

    publisher(new AppDevFakeSshExecutor)->publish(publisher_group('/srv/orbit/apps/shop/task-7'), 'Body', str_repeat('a', 40));

    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/requested_reviewers'));
});

it('requests the configured reviewers when it reuses an open pull request', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github(422);
    config(['orbit.tasks.review_request_logins' => ['reviewbot']]);

    expect(publisher(new AppDevFakeSshExecutor)->publish(publisher_group('/srv/orbit/apps/shop/task-7'), 'Body', str_repeat('a', 40)))->toBe('https://github.com/acme/shop/pull/12');
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.github.com/repos/acme/shop/pulls/12/requested_reviewers'
        && $request->data() === ['reviewers' => ['reviewbot']]
        && $request->hasHeader('Authorization', 'Bearer ghs_publish'));
});

it('still publishes when the requested reviewers POST fails', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github(201, 422);
    config(['orbit.tasks.review_request_logins' => ['reviewbot']]);
    Log::spy();

    expect(publisher(new AppDevFakeSshExecutor)->publish(publisher_group('/srv/orbit/apps/shop/task-7'), 'Body', str_repeat('a', 40)))->toBe('https://github.com/acme/shop/pull/11');
    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
        return $message === 'The task pull request reviewers could not be requested.'
            && $context['reason'] === 'GitHub refused the reviewer request (422): Review cannot be requested from pull request author.'
            && $context['repository'] === 'acme/shop'
            && $context['pull_request'] === 11
            && $context['reviewers'] === ['reviewbot'];
    });
});

it('refuses to publish without an App, for another host, or when the push fails', function (bool $project, string $repository, int $pushExit, string $message): void {
    if ($project) {
        GitHubTestSupport::storeApp();
    }
    publisher_github();
    $transport = new AppDevFakeSshExecutor([new CommandResult($pushExit, '', 'rejected', 1, false)]);

    expect(fn () => publisher($transport)->publish(publisher_group('/srv/orbit/apps/shop/task-7', $repository), 'Body', str_repeat('a', 40)))
        ->toThrow(TaskPullRequestException::class, $message);
})->with([
    'no App' => [false, 'git@github.com:acme/shop.git', 0, 'The Gateway GitHub App is not registered.'],
    'another host' => [true, 'git@gitlab.com:acme/shop.git', 0, 'The Project repository is not on github.com.'],
    'rejected push' => [true, 'git@github.com:acme/shop.git', 1, 'The task branch could not be pushed.'],
]);

it('names the Workflows permission when GitHub refuses a push that changes .github/workflows', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github();
    $stderr = "early-marker-should-not-appear ghs_publish\n"
        .str_repeat("remote: counting objects\n", 80)
        ."remote: refusing to allow a GitHub App to create or update workflow `.github/workflows/ci.yml` without `workflows` permission\n"
        ."error: failed to push some refs to 'https://x-access-token:ghs_publish@github.com/acme/shop.git'\n";
    $transport = new AppDevFakeSshExecutor([new CommandResult(1, '', $stderr, 1, false)]);

    expect(fn () => publisher($transport)->push(publisher_group('/srv/orbit/apps/shop/task-7'), str_repeat('a', 40)))
        ->toThrow(function (TaskPullRequestException $exception) use ($stderr): void {
            $message = $exception->getMessage();

            expect($message)->toContain('GitHub refused a change under .github/workflows/: grant the Orbit GitHub App the Workflows (read and write) permission, or push the commit yourself.')
                ->toContain('refusing to allow a GitHub App to create or update workflow `.github/workflows/ci.yml` without `workflows` permission')
                ->toContain('failed to push some refs')
                ->not->toContain('ghs_publish')
                ->not->toContain('early-marker-should-not-appear')
                ->and(strlen($message))->toBeLessThan((int) (strlen($stderr) / 2));
        });
});

it('includes the bounded tail of git stderr and redacts the token when another push fails', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github();
    $basic = base64_encode('x-access-token:ghs_publish');
    $stderr = "early-marker-should-not-appear\n"
        .str_repeat("remote: counting objects\n", 80)
        .'huge-line-start-should-be-cut '.str_repeat('x', 4000)." still-in-the-tail\n"
        ."error: failed to push some refs to 'https://github.com/acme/shop.git'\n"
        ."fatal: credential ghs_publish rejected; Authorization: Basic {$basic}\n";
    $transport = new AppDevFakeSshExecutor([new CommandResult(1, '', $stderr, 1, false)]);

    expect(fn () => publisher($transport)->publish(publisher_group('/srv/orbit/apps/shop/task-7'), 'Body', str_repeat('b', 40)))
        ->toThrow(function (TaskPullRequestException $exception) use ($stderr, $basic): void {
            $message = $exception->getMessage();

            expect($message)->toContain('The task branch could not be pushed.')
                ->toContain("error: failed to push some refs to 'https://github.com/acme/shop.git'")
                ->toContain('still-in-the-tail')
                ->toContain('[REDACTED]')
                ->not->toContain('Workflows')
                ->not->toContain('ghs_publish')
                ->not->toContain($basic)
                ->not->toContain(base64_encode('ghs_publish'))
                ->not->toContain('early-marker-should-not-appear')
                ->not->toContain('huge-line-start-should-be-cut')
                ->and(strlen($message))->toBeLessThan((int) (strlen($stderr) / 2));
        });
});

it('names the permissions GitHub has not granted instead of an unreachable GitHub', function (): void {
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['message' => 'The permissions requested are not granted to this installation.'], 422),
    ]);
    $transport = new AppDevFakeSshExecutor;

    expect(fn () => publisher($transport)->publish(publisher_group('/srv/orbit/apps/shop/task-7'), 'Body', str_repeat('a', 40)))
        ->toThrow(TaskPullRequestException::class, 'The pull request could not be opened: GitHub refused the Project token request (422): The permissions requested are not granted to this installation.');
    expect($transport->commands)->toBe([]);
});

it('still reports an unreachable GitHub when the token request fails on the server side', function (): void {
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['message' => 'Server Error'], 502),
    ]);

    expect(fn () => publisher(new AppDevFakeSshExecutor)->publish(publisher_group('/srv/orbit/apps/shop/task-7'), 'Body', str_repeat('a', 40)))
        ->toThrow(TaskPullRequestException::class, 'The pull request could not be opened: GitHub could not be reached or refused the Project credential.');
});
