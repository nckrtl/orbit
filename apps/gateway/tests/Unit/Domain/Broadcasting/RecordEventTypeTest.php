<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordEventType;

describe('RecordEventType', function (): void {
    it('matches the catalogue documented in docs/reference/events.mdx', function (): void {
        $values = array_map(static fn (RecordEventType $case): string => $case->value, RecordEventType::cases());

        expect($values)->toBe([
            'annotation.updated',
            'node.created',
            'node.updated',
            'node.deleted',
            'app.created',
            'app.updated',
            'app.deleted',
            'instance.created',
            'instance.updated',
            'instance.deleted',
            'process.created',
            'process.status',
            'process.deleted',
            'schedule.created',
            'schedule.updated',
            'schedule.deleted',
            'database.created',
            'database.updated',
            'database.deleted',
            'firewall.created',
            'firewall.deleted',
            'route.created',
            'route.updated',
            'route.deleted',
            'deploy_step.created',
            'deploy_step.updated',
            'deploy_step.deleted',
            'deployment.created',
            'deployment.updated',
            'process.usage',
            'task_group.created',
            'task_group.updated',
            'task_comment.created',
            'agent_thread.updated',
            'tasks.updated',
        ]);
    });

    it('uses the family singular dot verb naming convention for every type', function (): void {
        foreach (RecordEventType::cases() as $case) {
            expect($case->value)->toMatch('/\A[a-z_]+\.[a-z_]+\z/');
        }
    });
});
