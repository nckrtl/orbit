<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe(GatewayServingHost::class, function (): void {
    it('records and recognizes the bootstrap serving node', function (): void {
        $node = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
        ]);
        $host = app(GatewayServingHost::class);

        $host->remember($node);

        expect($host->nodeId())
            ->toBe($node->id)
            ->and($host->is($node))
            ->toBeTrue();
    });

    it('ignores an inactive recorded node', function (): void {
        $node = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Failed,
            'public_ssh_host' => '192.0.2.10',
        ]);
        $host = app(GatewayServingHost::class);
        $host->remember($node);

        expect($host->is($node))->toBeFalse();
    });
});
