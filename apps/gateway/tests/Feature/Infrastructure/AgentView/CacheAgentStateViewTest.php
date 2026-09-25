<?php

declare(strict_types=1);

use App\Domain\AgentView\AgentProcessView;
use App\Domain\AgentView\AgentStateView;
use App\Domain\AgentView\AgentViewFreshness;
use App\Domain\Processes\ProcessRuntime;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\Process;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

describe('the Gateway view of Node agents', function (): void {
    afterEach(fn () => Carbon::setTestNow());

    it('is missing until the subscriber writes a Node', function (): void {
        expect(app(AgentStateView::class)->node(7)->freshness)->toBe(AgentViewFreshness::Missing);
    });

    it('is fresh for fifteen seconds after the last agent event by the Gateway clock', function (): void {
        Carbon::setTestNow('2026-09-25 10:00:00');
        seed_agent_view(7, ['systemd:orbit-process-1-web' => 'active']);

        Carbon::setTestNow('2026-09-25 10:00:15');
        expect(app(AgentStateView::class)->node(7)->freshness)->toBe(AgentViewFreshness::Fresh);

        Carbon::setTestNow('2026-09-25 10:00:16');
        expect(app(AgentStateView::class)->node(7)->freshness)->toBe(AgentViewFreshness::Stale)
            ->and(app(AgentStateView::class)->node(7)->status(ProcessRuntime::Systemd, 'orbit-process-1-web'))->toBeNull();
    });

    it('expires a Node entry sixty seconds after its last write', function (): void {
        Carbon::setTestNow('2026-09-25 10:00:00');
        seed_agent_view(7, []);

        Carbon::setTestNow('2026-09-25 10:01:01');

        expect(app(AgentStateView::class)->node(7)->freshness)->toBe(AgentViewFreshness::Missing);
    })->skip(fn (): bool => config('cache.default') !== 'array', 'The array store honours Carbon test time.');

    it('reads an unlisted systemd unit as inactive and an unlisted container as exited', function (): void {
        seed_agent_view(7, []);
        $view = app(AgentStateView::class)->node(7);

        expect($view->status(ProcessRuntime::Systemd, 'orbit-process-1-web'))->toBe('inactive')
            ->and($view->status(ProcessRuntime::Docker, 'orbit-process-2-cache'))->toBe('exited')
            ->and($view->lists(ProcessRuntime::Docker, 'orbit-process-2-cache'))->toBeFalse();
    });

    it('does not answer for Docker while the agent reports Docker absent', function (): void {
        seed_agent_view(7, ['docker:orbit-process-2-cache' => 'running'], docker: 'absent');

        expect(app(AgentStateView::class)->node(7)->status(ProcessRuntime::Docker, 'orbit-process-2-cache'))->toBeNull();
    });

    it('answers a Process by the unit name on the Node that runs it', function (): void {
        $node = agent_view_node();
        $other = agent_view_node('app-prod', '10.44.0.4');
        $web = agent_view_instance_process($node, 'web');
        $cache = agent_view_instance_process($node, 'cache', ProcessRuntime::Docker);
        seed_agent_view($node->id, [
            "systemd:orbit-process-{$web->id}-web" => 'active',
            "docker:orbit-process-{$cache->id}-cache" => 'exited',
        ]);
        seed_agent_view($other->id, ["systemd:orbit-process-{$web->id}-web" => 'failed']);
        $processes = app(AgentProcessView::class);

        expect($processes->status($web))->toBe('active')
            ->and($processes->isStopped($web))->toBeFalse()
            ->and($processes->isStopped($cache))->toBeTrue()
            ->and($processes->lists($web))->toBeTrue()
            ->and($processes->statuses(Process::query()->get()))->toBe([
                $web->id => 'active',
                $cache->id => 'exited',
            ]);
    });

    it('does not answer for a Process whose Node has no fresh view', function (): void {
        $node = agent_view_node();
        $web = agent_view_instance_process($node, 'web');
        seed_agent_view($node->id, ["systemd:orbit-process-{$web->id}-web" => 'active'], ageSeconds: 16);
        $processes = app(AgentProcessView::class);

        expect($processes->status($web))->toBeNull()
            ->and($processes->lists($web))->toBeNull()
            ->and($processes->isStopped($web))->toBeNull()
            ->and($processes->statuses(Process::query()->get()))->toBe([]);
    });

    it('records the subscriber health for thirty seconds', function (): void {
        Carbon::setTestNow('2026-09-25 10:00:00');
        app(CacheAgentStateView::class)->putSubscriber(configured: true, connected: true, channels: 3);

        $health = app(AgentStateView::class)->subscriber();

        expect($health?->connected)->toBeTrue()
            ->and($health?->channels)->toBe(3)
            ->and($health?->isCurrent())->toBeTrue();

        Carbon::setTestNow('2026-09-25 10:00:31');
        expect($health?->isCurrent())->toBeFalse();
    });

    it('keeps the view in its own file store under ORBIT_HOME, never the default store', function (): void {
        config(['orbit.home' => $home = sys_get_temp_dir().'/orbit-agent-view-'.Str::random(8)]);
        app()->forgetInstance(CacheAgentStateView::class);

        try {
            app(CacheAgentStateView::class)->putNode(7, [], 'available', 1, CacheAgentStateView::now(), null);

            expect(glob($home.'/cache/agent-view/*/*/*'))->toHaveCount(1)
                ->and(Cache::get('agent-view.node.7'))->toBeNull()
                ->and(app(AgentStateView::class)->node(7)->freshness)->toBe(AgentViewFreshness::Fresh);
        } finally {
            (new Filesystem)->deleteDirectory($home);
        }
    });
});
