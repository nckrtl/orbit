<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppInstances\Logs\AppInstanceLogReader;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use SensitiveParameter;

final readonly class ShowAppInstanceLogsAction
{
    /** A shorter value would blank ordinary words, such as `true` or `local`, out of the log. */
    private const int ShortestRedactedValue = 8;

    public function __construct(
        private AppInstanceLogReader $logs,
        private CommandActivityInputSanitizer $sanitizer,
    ) {}

    public function execute(AppInstance $instance, int $lines): string
    {
        return $this->sanitizer->redactText(
            $this->redactEnvironmentValues($instance, $this->logs->tail($instance, $lines)),
        );
    }

    private function redactEnvironmentValues(
        AppInstance $instance,
        #[SensitiveParameter]
        string $logs,
    ): string {
        $values = $instance->environmentValues
            ->map(static fn (AppInstanceEnvironmentValue $value): string => $value->env_value)
            ->filter(static fn (string $value): bool => strlen($value) >= self::ShortestRedactedValue)
            ->unique()
            ->values()
            ->all();

        usort($values, static fn (string $first, string $second): int => strlen($second) <=> strlen($first));

        return str_replace(search: $values, replace: '[REDACTED]', subject: $logs);
    }
}
