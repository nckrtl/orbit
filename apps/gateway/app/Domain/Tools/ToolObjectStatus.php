<?php

declare(strict_types=1);

namespace App\Domain\Tools;

/**
 * Status values a Tool response may carry. `removed` is response-only.
 * Persisted rows use {@see ToolStatus} and never store `removed`.
 */
enum ToolObjectStatus: string
{
    case Installing = 'installing';
    case Installed = 'installed';
    case Updating = 'updating';
    case Removing = 'removing';
    case Failed = 'failed';
    case Removed = 'removed';

    public static function fromStored(ToolStatus $status): self
    {
        return match ($status) {
            ToolStatus::Installing => self::Installing,
            ToolStatus::Installed => self::Installed,
            ToolStatus::Updating => self::Updating,
            ToolStatus::Removing => self::Removing,
            ToolStatus::Failed => self::Failed,
        };
    }
}
