<?php

declare(strict_types=1);

namespace App\Infrastructure\ProjectDocuments;

use App\Domain\Shared\ResourceOperationException;
use App\Models\ProjectDocumentStorage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Independent durable state: never uses the configuration database connection. */
final readonly class ProbeJournal
{
    public function create(#[SensitiveParameter] ProjectDocumentStorage $storage): ProbeCleanupRecord
    {
        try {
            if ($storage->endpoint === null || $storage->region === null || $storage->bucket === null
                || $storage->access_key_id === null || $storage->secret_access_key === null) {
                throw new RuntimeException('Incomplete document probe configuration.');
            }
            $id = Str::uuid()->toString();
            $access = Crypt::encryptString($storage->access_key_id);
            $secret = Crypt::encryptString($storage->secret_access_key);
            $statement = $this->database()->prepare('INSERT INTO probes (id, endpoint, region, bucket, access_key_id, secret_access_key, next_attempt_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([$id, $storage->endpoint, $storage->region, $storage->bucket, $access, $secret, now()->getTimestamp() + 60]);
            $statement->closeCursor();

            return new ProbeCleanupRecord($id, $storage->endpoint, $storage->region, $storage->bucket, $access, $secret, 0);
        } catch (Throwable) {
            throw $this->unavailable();
        }
    }

    public function find(string $id): ProbeCleanupRecord
    {
        $statement = $this->database()->prepare('SELECT * FROM probes WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        if (! is_array($row)) {
            throw $this->unavailable();
        }
        foreach (['id', 'endpoint', 'region', 'bucket', 'access_key_id', 'secret_access_key'] as $field) {
            if (! isset($row[$field]) || ! is_string($row[$field])) {
                throw $this->unavailable();
            }
        }

        if (! isset($row['failure_count']) || ! is_int($row['failure_count'])) {
            throw $this->unavailable();
        }

        return new ProbeCleanupRecord($row['id'], $row['endpoint'], $row['region'], $row['bucket'], $row['access_key_id'], $row['secret_access_key'], $row['failure_count']);
    }

    /** @return list<string> */
    public function claimDue(): array
    {
        $statement = $this->database()->prepare('UPDATE probes SET next_attempt_at = ? WHERE id IN (SELECT id FROM probes WHERE next_attempt_at <= ? ORDER BY next_attempt_at, id LIMIT 20) RETURNING id');
        $statement->execute([now()->getTimestamp() + 600, now()->getTimestamp()]);
        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
        $statement->closeCursor();

        return array_values(array_map(static fn (mixed $id): string => is_string($id) ? $id : '', $ids));
    }

    public function completeAttempt(string $id, bool $succeeded): void
    {
        $database = $this->database();
        $statement = $database->prepare('SELECT failure_count FROM probes WHERE id = ?');
        $statement->execute([$id]);
        $failures = $succeeded ? 0 : min(7, max(0, (int) $statement->fetchColumn()) + 1);
        $statement->closeCursor();
        $delay = $succeeded ? 60 : min(3600, 60 * (2 ** ($failures - 1)));
        $update = $database->prepare('UPDATE probes SET failure_count = ?, next_attempt_at = ?, last_attempt_at = ?, last_error_code = ? WHERE id = ?');
        $update->execute([$failures, now()->getTimestamp() + $delay, now()->getTimestamp(), $succeeded ? null : 'project_documents.storage_unavailable', $id]);
        $update->closeCursor();
    }

    public function repair(string $id, #[SensitiveParameter] string $access, #[SensitiveParameter] string $secret): void
    {
        $this->find($id);
        if ($access === '' || $secret === '' || strlen($access) > 1024 || strlen($secret) > 1024) {
            throw $this->unavailable();
        }
        $statement = $this->database()->prepare('UPDATE probes SET access_key_id = ?, secret_access_key = ?, failure_count = 0, next_attempt_at = ?, last_error_code = NULL WHERE id = ?');
        $statement->execute([Crypt::encryptString($access), Crypt::encryptString($secret), now()->getTimestamp(), $id]);
        $statement->closeCursor();
    }

    /**
     * Read only; inventory must not create or modify the probe journal.
     *
     * @return list<string>
     */
    public function inventoryKeys(ProjectDocumentStorage $storage): array
    {
        $directory = config()->string('orbit.home').'/project-document-probes';
        $path = $directory.'/journal.sqlite';
        clearstatcache();
        if (@lstat($directory) === false) {
            return [];
        }
        foreach ([$directory, $path] as $item) {
            $stat = @lstat($item);
            $isDirectory = $item === $directory;
            if ($stat === false || $stat['uid'] !== posix_geteuid()
                || ($stat['mode'] & 0170000) !== ($isDirectory ? 0040000 : 0100000)
                || ($stat['mode'] & 07777) !== ($isDirectory ? 0700 : 0600)) {
                throw $this->unavailable();
            }
        }
        $database = new PDO('sqlite:file:'.$path.'?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $statement = $database->prepare('SELECT id FROM probes WHERE endpoint = ? AND region = ? AND bucket = ? ORDER BY id');
        $statement->execute([$storage->endpoint, $storage->region, $storage->bucket]);

        return array_values(array_map(fn (mixed $id): string => 'orbit-document-probes/'.(is_string($id) ? $id : throw $this->unavailable()), $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function database(): PDO
    {
        $directory = config()->string('orbit.home').'/project-document-probes';
        if ((! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory))
            || is_link($directory) || (fileperms($directory) & 077) !== 0) {
            throw $this->unavailable();
        }
        $path = $directory.'/journal.sqlite';
        if (! file_exists($path)) {
            $file = fopen($path, 'x');
            if ($file === false) {
                throw $this->unavailable();
            }
            fclose($file);
            if (! chmod($path, 0600)) {
                throw $this->unavailable();
            }
        }
        if (is_link($path) || (fileperms($path) & 077) !== 0) {
            throw $this->unavailable();
        }
        $database = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database->exec('PRAGMA busy_timeout = 5000');
        $database->exec('PRAGMA synchronous = FULL');
        $database->exec('CREATE TABLE IF NOT EXISTS probes (id TEXT PRIMARY KEY, endpoint TEXT NOT NULL, region TEXT NOT NULL, bucket TEXT NOT NULL, access_key_id TEXT NOT NULL, secret_access_key TEXT NOT NULL, next_attempt_at INTEGER NOT NULL, failure_count INTEGER NOT NULL DEFAULT 0, last_attempt_at INTEGER, last_error_code TEXT)');
        $database->exec('CREATE INDEX IF NOT EXISTS probes_due ON probes (next_attempt_at, id)');

        return $database;
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('project_documents.storage_unavailable', 'Document probe recovery is unavailable.', 503);
    }
}
