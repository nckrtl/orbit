<?php

declare(strict_types=1);

namespace App\E2E\State;

use App\E2E\Value\OperationId;
use Closure;
use JsonException;
use RuntimeException;
use Throwable;

/** @mago-expect lint:cyclomatic-complexity Atomic append rollback requires explicit failure checks. */
final readonly class OperationJournal
{
    public function __construct(
        private StatePaths $paths,
        private SecretRedactor $redactor = new SecretRedactor,
        private ?Closure $failure = null,
    ) {}

    /** @param array<string, mixed> $entry */
    public function append(OperationId $operation, array $entry): void
    {
        $file = $this->paths->ensureParent('journals/'.$operation->value.'.jsonl');
        $handle = fopen($file, 'ab');

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock the operation journal.');
        }

        try {
            if (fseek($handle, 0, SEEK_END) !== 0) {
                throw new RuntimeException('Unable to seek the operation journal.');
            }

            $offset = ftell($handle);

            if ($offset === false) {
                throw new RuntimeException('Unable to determine the operation journal offset.');
            }

            $permissionResult = $this->failure?->__invoke('journal_chmod', $handle, $offset);

            if ($permissionResult === false || ! chmod($file, 0600)) {
                throw new RuntimeException('Unable to protect the operation journal. No record was committed.');
            }

            $record = $this->redactor->redactArray([
                ...$entry,
                'operation_id' => $operation->value,
                'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            $written = fwrite($handle, $line);

            $this->failure?->__invoke('after_append_write', $handle, $offset);

            if ($written !== strlen($line) || ! fflush($handle) || function_exists('fsync') && ! fsync($handle)) {
                throw new RuntimeException('Unable to persist the operation journal.');
            }

            $this->failure?->__invoke('after_append_persist', $handle, $offset);
        } catch (Throwable $exception) {
            if (
                isset($offset)
                && (! ftruncate($handle, $offset)
                || ! fflush($handle)
                || function_exists('fsync')
                && ! fsync($handle))
            ) {
                throw new RuntimeException('Unable to roll back the operation journal.', previous: $exception);
            }

            throw $exception;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return list<array<array-key, mixed>> */
    public function entries(OperationId $operation): array
    {
        $file = $this->paths->path('journals/'.$operation->value.'.jsonl');

        if (! file_exists($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException('Unable to read the operation journal.');
        }

        $entries = [];

        foreach ($lines as $line) {
            if ($line === '') {
                throw new RuntimeException('The operation journal contains an empty record.');
            }

            try {
                $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('The operation journal is malformed.', previous: $exception);
            }

            if (! is_array($entry) || array_is_list($entry)) {
                throw new RuntimeException('The operation journal record is invalid.');
            }

            if (($entry['operation_id'] ?? null) !== $operation->value) {
                throw new RuntimeException('The operation journal record belongs to a different operation.');
            }

            $entries[] = $entry;
        }

        return $entries;
    }
}
