<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\ExporterDegradationRepository;
use App\Domain\Metrics\MetricsReconcileDegradationRepository;
use App\Models\Node;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

describe('component-owned service degradation', function (): void {
    it('preserves service metadata when exporter metadata is replaced or cleared', function (): void {
        $node = Node::query()->create(['name' => 'recovering', 'public_ssh_host' => '192.0.2.81', 'user' => 'orbit']);
        $repository = app(ExporterDegradationRepository::class);
        $repository->recordReconcileFailure($node->id, 'snapshot', 'metrics.service_inspection_failed');

        $repository->put($node->id, ExporterDegradationReason::Unreachable);
        $repository->forget($node->id);
        $repository->forgetReconcileFailures();
        app(MetricsReconcileDegradationRepository::class)->forgetAll();

        expect($repository->get($node->id))->toBe(ExporterDegradationReason::ReconcileFailed);
        expect($repository->step($node->id))->toBe('snapshot');
        expect(app(MetricsReconcileDegradationRepository::class)->errorCode($node->id))->toBe('metrics.service_inspection_failed');
    });

    it('atomically records service metadata when a write fails', function (bool $existing): void {
        $node = Node::query()->create(['name' => 'recording', 'public_ssh_host' => '192.0.2.81', 'user' => 'orbit']);
        $repository = app(ExporterDegradationRepository::class);
        if ($existing) {
            $repository->recordReconcileFailure($node->id, 'snapshot', 'metrics.service_inspection_failed');
        }
        $key = ExporterDegradationRepository::ServiceErrorKey;
        foreach (['INSERT', 'UPDATE'] as $operation) {
            DB::unprepared("CREATE TRIGGER fail_service_error_{$operation}
                BEFORE {$operation} ON settings
                WHEN NEW.key = '{$key}'
                BEGIN SELECT RAISE(ABORT, 'Injected metadata write failure.'); END");
        }

        try {
            expect(fn () => $repository->recordReconcileFailure($node->id, 'converge', 'metrics.service_convergence_failed'))
                ->toThrow(QueryException::class, 'Injected metadata write failure.');
        } finally {
            DB::unprepared('DROP TRIGGER fail_service_error_INSERT');
            DB::unprepared('DROP TRIGGER fail_service_error_UPDATE');
        }

        expect($repository->get($node->id))->toBe($existing ? ExporterDegradationReason::ReconcileFailed : null);
        expect($repository->step($node->id))->toBe($existing ? 'snapshot' : null);
        expect(app(MetricsReconcileDegradationRepository::class)->errorCode($node->id))
            ->toBe($existing ? 'metrics.service_inspection_failed' : null);
    })->with([false, true]);

    it('atomically clears all recovered Nodes when a delete fails', function (): void {
        $first = Node::query()->create(['name' => 'first', 'public_ssh_host' => '192.0.2.81', 'user' => 'orbit']);
        $second = Node::query()->create(['name' => 'second', 'public_ssh_host' => '192.0.2.82', 'user' => 'orbit']);
        $repository = app(ExporterDegradationRepository::class);
        foreach ([$first, $second] as $node) {
            $repository->recordReconcileFailure($node->id, 'snapshot', 'metrics.service_inspection_failed');
        }
        $key = ExporterDegradationRepository::ServiceErrorKey;
        DB::unprepared("CREATE TRIGGER fail_service_error_delete
            BEFORE DELETE ON settings
            WHEN OLD.key = '{$key}' AND OLD.scope_id = {$second->id}
            BEGIN SELECT RAISE(ABORT, 'Injected metadata delete failure.'); END");

        try {
            expect(fn () => $repository->forgetServiceFailures([$first->id, $second->id]))
                ->toThrow(QueryException::class, 'Injected metadata delete failure.');
        } finally {
            DB::unprepared('DROP TRIGGER fail_service_error_delete');
        }

        foreach ([$first, $second] as $node) {
            expect($repository->get($node->id))->toBe(ExporterDegradationReason::ReconcileFailed);
            expect($repository->step($node->id))->toBe('snapshot');
            expect(app(MetricsReconcileDegradationRepository::class)->errorCode($node->id))->toBe('metrics.service_inspection_failed');
        }

        $repository->forgetServiceFailures([$first->id, $second->id]);

        foreach ([$first, $second] as $node) {
            expect($repository->get($node->id))->toBeNull();
            expect($repository->step($node->id))->toBeNull();
            expect(app(MetricsReconcileDegradationRepository::class)->errorCode($node->id))->toBeNull();
        }
    });
});
