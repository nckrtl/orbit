<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Compute\SandboxImageStatus;
use App\Domain\Compute\SandboxImageStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One nightly build of the UpCloud sandbox base template (ADR 0204). The row is reserved before any
 * provider call and keeps the identities of the build VM, the template, and the smoke VM.
 *
 * @property string $id
 * @property string $provider
 * @property string $zone
 * @property SandboxImageStatus $status
 * @property SandboxImageStep $step
 * @property Carbon $step_started_at
 * @property string|null $failed_step
 * @property string $script_sha256
 * @property string $credential_fingerprint
 * @property Carbon|null $build_create_attempted_at
 * @property string|null $build_server_id
 * @property string|null $build_disk_id
 * @property string|null $build_address
 * @property array{type: string, value: string, fingerprint: string}|null $build_host_key
 * @property Carbon|null $clean_attempted_at
 * @property Carbon|null $templatize_attempted_at
 * @property string|null $template_id
 * @property Carbon|null $smoke_create_attempted_at
 * @property string|null $smoke_server_id
 * @property string|null $smoke_disk_id
 * @property string|null $smoke_address
 * @property array{type: string, value: string, fingerprint: string}|null $smoke_host_key
 * @property array<string, mixed>|null $caches
 * @property string|null $error_code
 * @property string|null $error_detail
 * @property Carbon|null $published_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $retired_at
 * @property Carbon|null $created_at
 */
final class SandboxImage extends Model
{
    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    /** @var list<string> */
    #[\Override]
    protected $guarded = [];

    /** The newest published template in the zone, or null when no build has published one. */
    public static function newestPublished(string $zone): ?self
    {
        return self::query()->where('provider', 'upcloud')->where('zone', $zone)
            ->where('status', SandboxImageStatus::Published->value)
            ->orderByDesc('published_at')->orderByDesc('created_at')->first();
    }

    public function name(string $role): string
    {
        return 'orbit-image-'.$role.'-'.$this->id;
    }

    public function templateTitle(): string
    {
        return 'orbit-sandbox-base-'.$this->id;
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => SandboxImageStatus::class, 'step' => SandboxImageStep::class, 'step_started_at' => 'datetime',
            'build_create_attempted_at' => 'datetime', 'build_host_key' => 'array', 'clean_attempted_at' => 'datetime',
            'templatize_attempted_at' => 'datetime', 'smoke_create_attempted_at' => 'datetime', 'smoke_host_key' => 'array',
            'caches' => 'array', 'published_at' => 'datetime', 'finished_at' => 'datetime', 'retired_at' => 'datetime',
        ];
    }
}
