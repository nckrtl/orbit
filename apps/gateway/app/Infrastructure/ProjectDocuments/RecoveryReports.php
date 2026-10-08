<?php

declare(strict_types=1);

namespace App\Infrastructure\ProjectDocuments;

use App\Domain\Shared\ResourceOperationException;
use Throwable;

final readonly class RecoveryReports
{
    public function path(string $id): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $id) !== 1) {
            throw $this->invalid();
        }

        return config()->string('orbit.home').'/project-document-recovery/reports/'.$id.'.json';
    }

    /** @param array<string, mixed> $report */
    public function publish(string $id, array $report): string
    {
        $path = $this->path($id);
        $this->directories(true);
        $bytes = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $temporary = dirname($path).'/.report-'.bin2hex(random_bytes(16));
        $handle = @fopen($temporary, 'x+b');
        if ($handle === false) {
            throw $this->unavailable();
        }
        try {
            if (! @chmod($temporary, 0600) || @fwrite($handle, $bytes) !== strlen($bytes)
                || ! @fflush($handle) || ! @fsync($handle) || @lstat($path) !== false || ! @rename($temporary, $path)) {
                throw $this->unavailable();
            }
        } finally {
            fclose($handle);
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return hash('sha256', $bytes);
    }

    /** @return array<string, mixed> */
    public function read(string $id, ?string $sha256 = null): array
    {
        try {
            $this->directories(false);
            $path = $this->path($id);
            $bytes = $this->privateBytes($path);
            if ($sha256 !== null && ! hash_equals($sha256, hash('sha256', $bytes))) {
                throw $this->invalid();
            }
            $report = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($report) || ($report['schema_version'] ?? null) !== 1 || ($report['report_id'] ?? null) !== $id
                || ($report['serialization_schema'] ?? null) !== DocumentRecoveryInventory::SCHEMA) {
                throw $this->invalid();
            }

            return $report;
        } catch (Throwable) {
            throw $this->invalid();
        }
    }

    /** @return array<string, mixed>|null */
    public function resolution(?string $path): ?array
    {
        if ($path === null) {
            return null;
        }
        try {
            $data = json_decode($this->privateBytes($path), true, 32, JSON_THROW_ON_ERROR);
            $fields = is_array($data) ? array_keys($data) : [];
            sort($fields);
            if (! is_array($data) || $fields !== ['prior_report_id', 'resolutions'] || ! is_string($data['prior_report_id'])
                || ! is_array($data['resolutions']) || ! array_is_list($data['resolutions']) || $data['resolutions'] === []) {
                throw $this->invalid();
            }
            $prior = $this->read($data['prior_report_id']);
            if (! is_array($prior['differences'] ?? null)) {
                throw $this->invalid();
            }
            $keys = array_column($prior['differences'], 'key');
            $seen = [];
            foreach ($data['resolutions'] as $record) {
                if (! is_array($record) || ! is_string($record['key'] ?? null) || ! in_array($record['key'], $keys, true)
                    || in_array($record['key'], $seen, true) || ! in_array($record['action'] ?? null, ['restore_bytes', 'restore_metadata', 'preserve_then_remove'], true)
                    || ! is_string($record['evidence_reference'] ?? null) || trim($record['evidence_reference']) === '') {
                    throw $this->invalid();
                }
                $seen[] = $record['key'];
                $fields = ['key', 'action', 'evidence_reference'];
                if ($record['action'] === 'preserve_then_remove') {
                    $fields = [...$fields, 'recovery_backup', 'size_bytes', 'sha256'];
                    if (! is_string($record['recovery_backup'] ?? null) || trim($record['recovery_backup']) === ''
                        || ! is_int($record['size_bytes'] ?? null) || $record['size_bytes'] < 0
                        || ! is_string($record['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $record['sha256']) !== 1) {
                        throw $this->invalid();
                    }
                }
                $actual = array_keys($record);
                sort($actual);
                sort($fields);
                if ($actual !== $fields) {
                    throw $this->invalid();
                }
            }

            return ['prior_report_id' => $data['prior_report_id'], 'resolutions' => $data['resolutions']];
        } catch (Throwable) {
            throw new ResourceOperationException('project_documents.cleanup_input_invalid', 'Invalid recovery resolution input.', 422);
        }
    }

    private function privateBytes(string $path): string
    {
        $this->ancestors(dirname($path));
        $this->validate($path, false);
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw $this->invalid();
        }

        return $bytes;
    }

    private function directories(bool $create): void
    {
        $home = rtrim(config()->string('orbit.home'), '/');
        $this->ancestors($home);
        foreach ([$home.'/project-document-recovery', $home.'/project-document-recovery/reports'] as $directory) {
            clearstatcache(true, $directory);
            if ($create && @lstat($directory) === false && ! @mkdir($directory, 0700)) {
                throw $this->unavailable();
            }
            $this->validate($directory, true);
        }
    }

    private function ancestors(string $directory): void
    {
        if (! str_starts_with($directory, '/') || str_contains($directory, '/../') || str_contains($directory, '/./')) {
            throw $this->unavailable();
        }
        for ($parent = $directory; ; $parent = dirname($parent)) {
            clearstatcache(true, $parent);
            $stat = @lstat($parent);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0040000
                || ! in_array($stat['uid'], [0, posix_geteuid()], true)
                || (($stat['mode'] & 0022) !== 0 && ($stat['mode'] & 01000) === 0)) {
                throw $this->unavailable();
            }
            if ($parent === '/') {
                break;
            }
        }
    }

    private function validate(string $path, bool $directory): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || $stat['uid'] !== posix_geteuid()
            || ($stat['mode'] & 0170000) !== ($directory ? 0040000 : 0100000)
            || ($stat['mode'] & 07777) !== ($directory ? 0700 : 0600) || (! $directory && $stat['nlink'] !== 1)) {
            throw $this->unavailable();
        }
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException(CleanupGate::ERROR_CODE, 'Private recovery state is unavailable.', 503);
    }

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('project_documents.cleanup_report_invalid', 'Reconcile again before resuming.', 409);
    }
}
