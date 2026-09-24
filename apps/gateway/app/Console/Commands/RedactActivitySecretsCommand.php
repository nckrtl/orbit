<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\Activity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

final class RedactActivitySecretsCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:activity-redact {--dry-run : Count the records that need redaction without changing them}';

    #[\Override]
    protected $description = 'Redact secrets that older Gateway versions stored in Activity properties.';

    public function handle(CommandActivityInputSanitizer $sanitizer): int
    {
        $table = new Activity()->getTable();
        $dryRun = $this->option('dry-run') === true;
        $scanned = 0;
        $redacted = 0;

        DB::table($table)->select(['id', 'properties'])->orderBy('id')->chunkById(
            500,
            function ($rows) use ($sanitizer, $table, $dryRun, &$scanned, &$redacted): void {
                foreach ($rows as $row) {
                    $scanned++;
                    $properties = $this->decode($row->properties);

                    if ($properties === null) {
                        continue;
                    }

                    $sanitized = $sanitizer->sanitizeProperties($properties);

                    if ($sanitized === $properties) {
                        continue;
                    }

                    $redacted++;

                    if (! $dryRun) {
                        DB::table($table)->where('id', $row->id)->update([
                            'properties' => json_encode($sanitized, JSON_THROW_ON_ERROR),
                        ]);
                    }
                }
            },
        );

        $this->line($dryRun
            ? "Scanned {$scanned} Activity records. Would redact {$redacted}."
            : "Scanned {$scanned} Activity records. Redacted {$redacted}.");

        return self::SUCCESS;
    }

    /** @return array<array-key, mixed>|null */
    private function decode(mixed $stored): ?array
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
