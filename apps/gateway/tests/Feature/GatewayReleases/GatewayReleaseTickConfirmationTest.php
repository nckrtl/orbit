<?php

declare(strict_types=1);

use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskTickClock;
use App\Models\GatewayRelease;
use Carbon\CarbonImmutable;
use Tests\Support\GatewayReleasePipeline;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T06:05:10Z'));
    $this->pipeline = new GatewayReleasePipeline;
    app(TaskExtensionState::class)->enable();
});

afterEach(function (): void {
    $this->pipeline->cleanup();
});

/** A `tasks:tick` that starts now in a scheduler running `$sha`. */
function tick_from(string $sha): void
{
    config(['app.version' => $sha]);
    app(TaskTickClock::class)->record();
}

function tick_phase(string $sha): array
{
    return GatewayRelease::query()->where('sha', $sha)->latest('id')->firstOrFail()->phases['tick'];
}

describe('post-release tick confirmation', function (): void {
    it('starts a verified release pending, and a runner tick confirms it once the release itself has ticked', function (): void {
        $previous = $this->pipeline->adopt();
        $sha = $this->pipeline->fixture->commit('Manual release');

        $release = $this->pipeline->deployer()->execute($sha);

        expect($release->outcome)->toBe('verified')
            ->and(tick_phase($sha))->toBe([
                'outcome' => 'pending',
                'since' => '2026-10-08T06:05:10Z',
                'deadline' => '2026-10-08T06:08:10Z',
            ]);

        // The previous release's scheduler may still tick after the handoff began. That is not the new scheduler.
        $this->travel(20)->seconds();
        tick_from($previous);
        // Automatic releases are disabled, so the runner tick returns early, after it checked the confirmation.
        expect($this->pipeline->automatic()->execute()['result'])->toBe('disabled')
            ->and(tick_phase($sha)['outcome'])->toBe('pending');

        $this->travel(30)->seconds();
        tick_from($sha);
        $this->pipeline->automatic()->execute();

        expect(tick_phase($sha))->toMatchArray([
            'outcome' => 'confirmed',
            'last_tick_at' => '2026-10-08T06:06:00.000000Z',
            'last_tick_version' => $sha,
            'decided_at' => '2026-10-08T06:06:00Z',
        ])->and($this->pipeline->alerts)->toBe([]);
    });

    it('confirms at once when the new scheduler ticked before the release finished', function (): void {
        $this->pipeline->adopt();
        $sha = $this->pipeline->fixture->commit('Release');
        $this->pipeline->onVerify = static fn () => tick_from($sha);

        $this->pipeline->deployer()->execute($sha);

        expect(tick_phase($sha)['outcome'])->toBe('confirmed');
    });

    it('does not count a tick of the same commit from before the handoff', function (): void {
        $this->pipeline->adopt();
        $sha = $this->pipeline->fixture->commit('Release');
        tick_from($sha);
        $this->travel(5)->minutes();

        $this->pipeline->deployer()->execute($sha);
        $this->pipeline->automatic()->execute();

        expect(tick_phase($sha)['outcome'])->toBe('pending');
    });

    it('marks a silent scheduler missed and alerts once, without a switch-back or a pause, so a forward fix still ships', function (): void {
        $previous = $this->pipeline->adopt();
        $this->pipeline->automation()->enable();
        $silent = $this->pipeline->green = $this->pipeline->fixture->commit('Silent scheduler');

        expect($this->pipeline->automatic()->execute()['result'])->toBe('released');
        $this->pipeline->green = null;

        tick_from($previous);
        $this->travel(179)->seconds();
        $this->pipeline->automatic()->execute();
        expect(tick_phase($silent)['outcome'])->toBe('pending')
            ->and($this->pipeline->alerts)->toBe([]);

        $this->travel(2)->seconds();
        expect($this->pipeline->automatic()->execute()['result'])->toBe('up_to_date');

        $missed = tick_phase($silent);
        expect($missed)->toMatchArray([
            'outcome' => 'missed',
            'last_tick_version' => $previous,
            'decided_at' => '2026-10-08T06:08:11Z',
        ])
            ->and($missed['alert']['kind'])->toBe('release_scheduler_silent')
            ->and($this->pipeline->alerts)->toHaveCount(1)
            ->and($this->pipeline->alerts[0]->kind)->toBe(ReleaseAlertKind::ReleaseSchedulerSilent)
            ->and($this->pipeline->alerts[0]->subject->sha)->toBe($silent)
            ->and($this->pipeline->alerts[0]->summary)->toContain('ran no tasks:tick between 2026-10-08T06:05:10Z and 2026-10-08T06:08:10Z')
            ->and($this->pipeline->alerts[0]->summary)->toContain('ran version '.$previous)
            // The record keeps its own outcome and alert; the release stays live and automation stays on.
            ->and(GatewayRelease::query()->where('sha', $silent)->sole())
            ->outcome->toBe('verified')
            ->alert->toBeNull()
            ->and($this->pipeline->fixture->layout->currentReleaseId())->toBe(substr($silent, 0, 12))
            ->and($this->pipeline->automation()->pause())->toBeNull();

        $this->pipeline->automatic()->execute();
        expect($this->pipeline->alerts)->toHaveCount(1);

        $fix = $this->pipeline->green = $this->pipeline->fixture->commit('Forward fix');
        expect($this->pipeline->automatic()->execute()['result'])->toBe('released')
            ->and($this->pipeline->fixture->layout->currentReleaseId())->toBe(substr($fix, 0, 12))
            ->and(tick_phase($fix)['outcome'])->toBe('pending');
    });

    it('skips the confirmation while the tasks extension is disabled', function (): void {
        $this->pipeline->adopt();
        $sha = $this->pipeline->fixture->commit('Release');
        app(TaskExtensionState::class)->disable();

        $this->pipeline->deployer()->execute($sha);

        expect(tick_phase($sha))->toMatchArray(['outcome' => 'skipped', 'reason' => 'tasks_disabled']);

        $this->travel(10)->minutes();
        $this->pipeline->automatic()->execute();
        expect($this->pipeline->alerts)->toBe([]);
    });

    it('supersedes a pending confirmation once another release is current', function (): void {
        $this->pipeline->adopt();
        $first = $this->pipeline->fixture->commit('First');
        $second = $this->pipeline->fixture->commit('Second');
        $this->pipeline->deployer()->execute($first);
        $this->travel(30)->seconds();
        $this->pipeline->deployer()->execute($second);

        $this->travel(10)->minutes();
        $this->pipeline->automatic()->execute();

        expect(tick_phase($first))->toMatchArray(['outcome' => 'superseded', 'current' => substr($second, 0, 12)])
            ->and(tick_phase($second)['outcome'])->toBe('missed')
            ->and($this->pipeline->alerts)->toHaveCount(1)
            ->and($this->pipeline->alerts[0]->subject->sha)->toBe($second);
    });
});
