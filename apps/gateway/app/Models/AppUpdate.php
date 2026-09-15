<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Apps\AppUpdateStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $app_id
 * @property AppUpdateStatus $status
 * @property string $fingerprint
 * @property string|null $requested_slug
 * @property string|null $requested_repository_url
 * @property string|null $requested_default_branch
 * @property string|null $requested_root
 * @property string $previous_slug
 * @property string $previous_repository_url
 * @property string|null $previous_default_branch
 * @property string|null $previous_root
 * @property array<string, mixed>|null $inventory
 * @property array<string, mixed>|null $evidence
 * @property string|null $error_code
 * @property-read App $app
 */
final class AppUpdate extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'app_id',
        'status',
        'fingerprint',
        'requested_slug',
        'requested_repository_url',
        'requested_default_branch',
        'requested_root',
        'previous_slug',
        'previous_repository_url',
        'previous_default_branch',
        'previous_root',
        'inventory',
        'evidence',
        'error_code',
    ];

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function mergeEvidence(array $evidence): void
    {
        $this->update([
            'evidence' => [
                ...($this->evidence ?? []),
                ...$evidence,
            ],
        ]);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AppUpdateStatus::class,
            'inventory' => 'array',
            'evidence' => 'array',
        ];
    }
}
