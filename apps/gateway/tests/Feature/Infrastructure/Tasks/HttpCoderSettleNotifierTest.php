<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\Tasks\HttpCoderSettleNotifier;
use App\Models\App as OrbitApp;
use App\Models\TaskGroup;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function coder_settle_group(): TaskGroup
{
    $app = OrbitApp::query()->create([
        'name' => 'coder-app',
        'slug' => 'coder-app',
        'repository_url' => 'git@github.com:nckrtl/orbit.git',
        'default_branch' => 'main',
    ]);

    return TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Settle notify',
        'brief' => 'Notify Coder after the PR opens.',
        'status' => TaskGroupStatus::Settling,
        'pr_url' => 'https://github.com/nckrtl/orbit/pull/543',
        'notify_coder' => true,
        'tokens' => 40,
        'line_diff' => 12,
        'duration_ms' => 1500,
    ]);
}

it('posts an HMAC-signed settle body to Coder', function (): void {
    $this->freezeTime();
    Http::preventStrayRequests();
    Http::fake([
        'https://coder.example.test/hooks/settle' => Http::response(['ok' => true]),
    ]);
    config()->set('orbit.tasks.coder_webhook_url', 'https://coder.example.test/hooks/settle');
    config()->set('orbit.tasks.coder_webhook_secret', 'coder-secret');

    $group = coder_settle_group();
    app(HttpCoderSettleNotifier::class)->notify($group);

    Http::assertSent(function (Request $request) use ($group): bool {
        $timestamp = (string) now()->timestamp;
        $body = $request->body();
        $expected = hash_hmac('sha256', $timestamp.'.'.$body, 'coder-secret');

        return $request->url() === 'https://coder.example.test/hooks/settle'
            && $request->hasHeader('X-Orbit-Timestamp', $timestamp)
            && $request->hasHeader('X-Orbit-Signature', 'sha256='.$expected)
            && $request->isJson()
            && $request->data() === [
                'event' => 'task_group.settled',
                'task_group_id' => $group->id,
                'title' => 'Settle notify',
                'tokens' => 40,
                'line_diff' => 12,
                'duration_ms' => 1500,
                'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/543',
            ]
            && ! str_contains($body, 'coder-secret');
    });
});

it('skips the webhook when the URL or secret is missing', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    config()->set('orbit.tasks.coder_webhook_url', 'https://coder.example.test/hooks/settle');
    config()->set('orbit.tasks.coder_webhook_secret', null);

    app(HttpCoderSettleNotifier::class)->notify(coder_settle_group());

    Http::assertNothingSent();
});

it('does not fail settle when Coder refuses the webhook', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://coder.example.test/hooks/settle' => Http::response(['error' => 'no'], 500),
    ]);
    config()->set('orbit.tasks.coder_webhook_url', 'https://coder.example.test/hooks/settle');
    config()->set('orbit.tasks.coder_webhook_secret', 'coder-secret');

    expect(fn () => app(HttpCoderSettleNotifier::class)->notify(coder_settle_group()))
        ->not->toThrow(Throwable::class);
});
