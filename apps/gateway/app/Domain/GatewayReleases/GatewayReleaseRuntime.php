<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/**
 * What a switched release does before it is verified: Caddy and PHP-FPM only when their rendered
 * output changed, the scheduler handoff, document-cleanup reconcile then resume, and agent-view.
 * Switch-back runs the same handoff for the release it returns to.
 *
 * @phpstan-type HandoffResult array{caddy: string, fpm: string, opcache?: string, scheduler: string, scheduler_unit: string|null, scheduler_drain?: array<string, mixed>|null, processes_restarted?: list<string>, cleanup: string, cleanup_error_code: string|null, agent_view: string, cleanup_paused: bool}
 */
interface GatewayReleaseRuntime
{
    /**
     * @return HandoffResult
     */
    public function handoff(string $id): array;
}
