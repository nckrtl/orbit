<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxImageStatus;
use App\Domain\Compute\SandboxState;
use App\Models\SandboxImage;
use App\Models\TaskSandbox;

/**
 * Keeps the newest published base template and the one before it (ADR 0204). An older template is
 * deleted once no sandbox that is not destroyed records it. Runs under the UpCloud lock, so no
 * reservation can pick a template while retention decides about it.
 */
final readonly class SandboxImageRetention
{
    public const int Keep = 2;

    public function __construct(private UpCloudClient $client, private ComputeLocks $locks) {}

    /** @return list<string> the template UUIDs deleted by this call */
    public function prune(): array
    {
        if (SandboxImage::query()->where('provider', 'upcloud')->where('status', SandboxImageStatus::Published->value)->count() <= self::Keep) {
            return [];
        }

        return $this->locks->upcloud(function (): array {
            $published = SandboxImage::query()->where('provider', 'upcloud')->where('status', SandboxImageStatus::Published->value)
                ->orderByDesc('published_at')->orderByDesc('created_at')->get();
            if ($published->count() <= self::Keep) {
                return [];
            }
            $used = TaskSandbox::query()->where('provider', 'upcloud')->where('state', '!=', SandboxState::Destroyed->value)->get()
                ->map(fn (TaskSandbox $sandbox): mixed => $sandbox->spec['image'] ?? null)->filter(fn (mixed $image): bool => is_string($image))->all();
            $deleted = [];
            foreach ($published->slice(self::Keep) as $image) {
                if ($image->template_id === null || in_array($image->template_id, $used, true)) {
                    continue;
                }
                $storage = data_get($this->client->request('GET', 'storage/'.$image->template_id, $image->credential_fingerprint, allowMissing: true), 'storage');
                if ($storage !== null) {
                    if (! is_array($storage) || ($storage['uuid'] ?? null) !== $image->template_id
                        || ($storage['title'] ?? null) !== $image->templateTitle() || ($storage['type'] ?? null) !== 'template') {
                        throw new ComputeException('compute.ownership_mismatch', 'The UpCloud template does not match the recorded image build.');
                    }
                    $this->client->request('DELETE', 'storage/'.$image->template_id, $image->credential_fingerprint);
                }
                $image->update(['status' => SandboxImageStatus::Retired, 'retired_at' => now()]);
                $deleted[] = $image->template_id;
            }

            return $deleted;
        });
    }
}
