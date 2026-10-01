<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\TaskSessionDecision;
use App\Domain\Tasks\TaskSessionObservation;
use App\Models\Task;
use Illuminate\Support\Facades\Http;
use JsonException;
use SensitiveParameter;
use Throwable;

final readonly class HttpCoderSettleNotifier implements CoderSettleNotifier
{
    private const float CONNECT_TIMEOUT = 3.0;

    private const float TIMEOUT = 10.0;

    public function notify(Task $group): void
    {
        $this->post([
            'event' => 'task_group.settled',
            'task_group_id' => $group->id,
            'title' => $group->title,
            'tokens' => max(0, (int) ($group->tokens ?? 0)),
            'line_diff' => max(0, (int) ($group->line_diff ?? 0)),
            'duration_ms' => max(0, (int) ($group->duration_ms ?? 0)),
            'questions' => max(0, (int) ($group->questions ?? 0)),
            'escalations' => max(0, (int) ($group->escalations ?? 0)),
            'pull_request_url' => $group->pr_url,
        ]);
    }

    public function escalate(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void
    {
        $this->post([
            'event' => 'task_group.escalated',
            'task_group_id' => $group->id,
            'title' => $group->title,
            'reason' => $decision->reason,
            'confidence' => $decision->confidence,
            'thread_id' => $observation->threads[0]->threadId ?? null,
            'observation' => $observation->toArray(),
        ]);
    }

    public function assistance(Task $group, string $reason): void
    {
        $kind = $group->assistance_kind;

        $this->post([
            'event' => 'task_group.assistance_requested',
            'task_group_id' => $group->id,
            'title' => $group->title,
            'kind' => $kind instanceof AssistanceKind ? $kind->value : null,
            'question' => $group->assistance_question,
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(array $payload): void
    {
        $url = $this->string(config('orbit.tasks.coder_webhook_url'));
        $secret = $this->string(config('orbit.tasks.coder_webhook_secret'));

        if ($url === null || $secret === null) {
            return;
        }

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return;
        }

        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        try {
            Http::connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->withBody($body, 'application/json')
                ->withHeaders([
                    'X-Orbit-Timestamp' => $timestamp,
                    'X-Orbit-Signature' => 'sha256='.$signature,
                ])
                ->post($url);
        } catch (Throwable) {
        }
    }

    private function string(#[SensitiveParameter] mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
