<?php

declare(strict_types=1);

namespace App\Infrastructure\ProjectDocuments;

use App\Data\ProjectDocuments\DocumentBody;
use App\Domain\Shared\ResourceOperationException;
use App\Models\ProjectDocumentStorage;
use Aws\Exception\AwsException;
use Aws\S3\S3ClientInterface;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class DocumentRecoveryInventory
{
    public const string SCHEMA = 'orbit-document-inventory-v1';

    private const array TABLES = ['project_document_cleanups', 'project_document_entries', 'project_document_uploads', 'project_document_versions'];

    public function __construct(private DocumentsFilesystem $filesystems, private DocumentBodies $bodies, private ProbeJournal $probes) {}

    /** @return array<string, mixed> */
    public function scan(): array
    {
        $this->bodies->assertOutsideTransaction();
        $result = ['serialization_schema' => self::SCHEMA, 'fingerprint_algorithm' => 'sha256',
            'destination' => null, 'database_fingerprint' => null, 'bucket_fingerprint' => null,
            'database_inventory' => [], 'bucket_inventory' => [], 'inventory_counts' => [],
            'verification' => [], 'classifications' => [], 'differences' => [], 'report_state' => 'incomplete'];
        try {
            $database = $this->database();
            $result['destination'] = $database['destination'];
            $result['database_inventory'] = $database['tables'];
            $result['database_fingerprint'] = $this->fingerprint($database);
            $storage = ProjectDocumentStorage::query()->findOrFail(1);
            if ([$storage->endpoint, $storage->region, $storage->bucket] !== $database['destination']) {
                throw $this->changed();
            }
            $disk = $this->filesystems->forConfiguration($storage);
            $client = $disk->getClient();
            $bucket = $storage->bucket;
            if ($bucket === null) {
                throw $this->provider();
            }
            $objects = $this->bucket($client, $bucket);
            $result['bucket_inventory'] = $objects;
            $result['bucket_fingerprint'] = $this->fingerprint($objects);
            $result['inventory_counts'] = array_map(count(...), $database['tables']) + ['bucket_objects' => count($objects)];
            [$differences, $classifications] = $this->references($database['tables']);
            $result['differences'] = $differences;
            $result['classifications'] = $classifications;
            $known = array_column($classifications, 'key');
            $probes = $this->probes->inventoryKeys($storage);
            foreach ($objects as $object) {
                if (in_array($object[0], $probes, true)) {
                    $result['classifications'][] = ['key' => $object[0], 'classification' => 'reserved_probe'];
                } elseif (! in_array($object[0], $known, true)) {
                    $result['differences'][] = ['key' => $object[0], 'kind' => 'unknown'];
                }
            }
            foreach ($database['tables']['project_document_versions'] as $version) {
                $verification = $this->verify($client, $bucket, $version);
                $result['verification'][] = $verification;
                if ($verification['result'] === 'verified') {
                    $listed = array_values(array_filter($objects, static fn (array $object): bool => $object[0] === $version['storage_key']));
                    if (count($listed) !== 1 || $listed[0][1] !== $verification['size_bytes']) {
                        throw $this->changed();
                    }
                }
                if ($verification['result'] !== 'verified') {
                    $result['differences'][] = ['key' => $version['storage_key'], 'kind' => $verification['result']];
                }
            }
            if ($this->fingerprint($this->database()) !== $result['database_fingerprint']
                || $this->fingerprint($this->bucket($client, $bucket)) !== $result['bucket_fingerprint']
                || $this->probes->inventoryKeys($storage) !== $probes) {
                throw $this->changed();
            }
            $objectKeys = array_column($objects, 0);
            foreach ($result['classifications'] as &$classification) {
                $classification['present'] = in_array($classification['key'], $objectKeys, true);
            }
            unset($classification);
            $result['report_state'] = 'complete';
        } catch (ResourceOperationException $exception) {
            $result['error_code'] = $exception->errorCode === 'project_documents.storage_not_configured' ? 'project_documents.storage_unavailable' : $exception->errorCode;
        } catch (Throwable) {
            $result['error_code'] = 'project_documents.storage_unavailable';
        }

        return $result;
    }

    /**
     * Canonical table order, numeric row order, alphabetical fixed field order, explicit nulls.
     * All extra cleanup eligibility columns are included, so adding claims cannot escape the binding.
     *
     * @return array{destination: list<string|null>, tables: array<string, list<array<string, int|string|null>>>}
     */
    private function database(): array
    {
        return DB::transaction(function (): array {
            $storage = DB::table('project_document_storages')->where('id', 1)->first();
            if ($storage === null) {
                throw $this->provider();
            }
            $tables = [];
            foreach (self::TABLES as $table) {
                $rows = [];
                foreach (DB::table($table)->orderBy('id')->get() as $row) {
                    $fields = get_object_vars($row);
                    ksort($fields, SORT_STRING);
                    foreach ($fields as $field => $value) {
                        if ($value === null) {
                            continue;
                        }
                        if (! is_string($value) && ! is_int($value) && ! is_bool($value)) {
                            throw $this->changed();
                        }
                        if (str_ends_with($field, '_at')) {
                            $fields[$field] = CarbonImmutable::parse((string) $value, 'UTC')->utc()->format('Y-m-d\TH:i:s.u\Z');
                        } elseif (in_array($field, ['id', 'project_id', 'parent_id', 'current_version_id', 'entry_id', 'upload_id', 'created_by_node_id', 'revision', 'number', 'size_bytes', 'sibling_scope', 'attempts', 'retained_fence', 'pending'], true)) {
                            $fields[$field] = (int) $value;
                        } else {
                            $fields[$field] = is_bool($value) ? (int) $value : $value;
                        }
                    }
                    $rows[] = $fields;
                }
                $tables[$table] = $rows;
            }

            return ['destination' => [$storage->endpoint, $storage->region, $storage->bucket], 'tables' => $tables];
        });
    }

    /** @return list<array{string, int, ?string, ?string, ?string}> */
    private function bucket(S3ClientInterface $client, string $bucket): array
    {
        $objects = [];
        $seenTokens = [];
        $token = null;
        do {
            $arguments = ['Bucket' => $bucket, 'MaxKeys' => 1000, 'EncodingType' => 'url', '@http' => [
                'timeout' => 30, 'connect_timeout' => 2, 'allow_redirects' => false, 'sink' => new RecoveryListBuffer]];
            if ($token !== null) {
                $arguments['ContinuationToken'] = $token;
            }
            $page = $client->listObjectsV2($arguments);
            if ($page['EncodingType'] !== 'url' || ! is_bool($page['IsTruncated']) || ($page['Contents'] !== null && ! is_array($page['Contents']))) {
                throw $this->provider();
            }
            $contents = $page['Contents'] ?? [];
            if (count($contents) > 1000 || ($page['IsTruncated'] && $contents === [])) {
                throw $this->provider();
            }
            foreach ($contents as $object) {
                // The SDK XML parser returns S3's long Size shape as a decimal string.
                $size = is_array($object) ? ($object['Size'] ?? null) : null;
                if (is_string($size) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $size) === 1 && (string) (int) $size === $size) {
                    $size = (int) $size;
                }
                if (! is_array($object) || ! is_string($object['Key'] ?? null) || ! is_int($size) || $size < 0
                    || preg_match('/%(?![a-fA-F0-9]{2})/', $object['Key']) === 1) {
                    throw $this->provider();
                }
                // V2 does not receive the SDK's ListObjects automatic decoding. Decode only the confirmed
                // URL-encoded key, once: plus signs and literal percent sequences are not form input or paths.
                $key = rawurldecode($object['Key']);
                if (array_key_exists($key, $objects)) {
                    throw $this->provider();
                }
                $modified = $object['LastModified'] ?? null;
                if ($modified !== null && ! $modified instanceof DateTimeInterface) {
                    throw $this->provider();
                }
                foreach (['ETag', 'VersionId'] as $marker) {
                    if (isset($object[$marker]) && ! is_string($object[$marker])) {
                        throw $this->provider();
                    }
                }
                $objects[$key] = [$key, $size, $modified === null ? null : CarbonImmutable::instance($modified)->utc()->format('Y-m-d\TH:i:s.u\Z'), is_string($object['ETag'] ?? null) ? $object['ETag'] : null, is_string($object['VersionId'] ?? null) ? $object['VersionId'] : null];
            }
            if (! $page['IsTruncated']) {
                break;
            }
            $next = $page['NextContinuationToken'];
            if (! is_string($next) || $next === '' || in_array($next, $seenTokens, true)) {
                throw $this->provider();
            }
            $seenTokens[] = $next;
            $token = $next;
        } while (true);
        ksort($objects, SORT_STRING);

        return array_values($objects);
    }

    /**
     * @param  array<string, int|string|null>  $version
     * @return array<string, mixed>
     */
    private function verify(S3ClientInterface $client, string $bucket, array $version): array
    {
        $size = (int) $version['size_bytes'];
        $sha256 = (string) $version['sha256'];
        $key = (string) $version['storage_key'];
        $result = ['version_id' => $version['id'], 'key' => $key, 'expected_size' => $size, 'expected_sha256' => $sha256, 'size_bytes' => null, 'sha256' => null, 'result' => 'corrupt'];
        if ($size < 0 || $size > DocumentBody::MAX_BYTES || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
            return $result;
        }
        $sink = new DocumentDigestSink($size);
        try {
            $response = $client->getObject(['Bucket' => $bucket, 'Key' => $key,
                '@http' => ['timeout' => 30, 'connect_timeout' => 2, 'allow_redirects' => false,
                    'sink' => $sink, 'on_headers' => $sink->onHeaders(...)]]);
            $metadata = $response['@metadata'];
            $status = $sink->responseStatus ?? (is_array($metadata) ? ($metadata['statusCode'] ?? null) : null);
            if (! is_int($status) || $status < 200 || $status >= 300) {
                throw $this->provider();
            }
            $result['size_bytes'] = $sink->size;
            $result['sha256'] = $sink->digest();
            $result['result'] = $sink->size === $size && hash_equals($sha256, $sink->digest()) ? 'verified' : 'corrupt';
        } catch (Throwable $exception) {
            if ($exception instanceof AwsException && $exception->getStatusCode() === 404
                && in_array($exception->getAwsErrorCode(), ['NoSuchKey', 'NotFound'], true)) {
                $result['result'] = 'missing';
            } else {
                $corrupt = false;
                if ($sink->responseStatus !== null && $sink->responseStatus >= 200 && $sink->responseStatus < 300) {
                    for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                        if ($cause instanceof DocumentBodyUnavailable) {
                            $corrupt = true;
                        }
                    }
                }
                if (! $corrupt) {
                    throw $this->provider();
                }
                $result['size_bytes'] = $sink->size;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, list<array<string, int|string|null>>>  $tables
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function references(array $tables): array
    {
        $entries = array_column($tables['project_document_entries'], null, 'id');
        $versions = array_column($tables['project_document_versions'], null, 'id');
        $uploads = array_column($tables['project_document_uploads'], null, 'id');
        $cleanups = array_column($tables['project_document_cleanups'], null, 'storage_key');
        $differences = [];
        $classes = [];
        $conflict = static function (string $key) use (&$differences): void {
            $differences[] = ['key' => $key, 'kind' => 'inconsistent'];
        };
        foreach ($entries as $entry) {
            $current = $versions[$entry['current_version_id'] ?? ''] ?? null;
            $parent = $entries[$entry['parent_id'] ?? ''] ?? null;
            if (! in_array($entry['kind'], ['file', 'folder'], true)
                || ($entry['kind'] === 'file' && ($current === null || $current['entry_id'] !== $entry['id']))
                || $entry['sibling_scope'] !== ($entry['parent_id'] ?? 0)
                || ($entry['kind'] === 'folder' && $entry['current_version_id'] !== null)
                || ($entry['parent_id'] !== null && ($parent === null || $parent['kind'] !== 'folder' || $parent['project_id'] !== $entry['project_id']))) {
                $conflict((string) ($current['storage_key'] ?? 'entry:'.$entry['id']));
            }
        }
        foreach ($entries as $entry) {
            $visited = [];
            $ancestor = $entry;
            while ($ancestor !== null) {
                if (in_array($ancestor['id'], $visited, true) || count($visited) >= 32) {
                    $conflict('entry:'.$entry['id']);
                    break;
                }
                $visited[] = $ancestor['id'];
                $ancestor = $entries[$ancestor['parent_id'] ?? ''] ?? null;
            }
        }
        foreach ($versions as $version) {
            $upload = $uploads[$version['upload_id'] ?? ''] ?? null;
            $entry = $entries[$version['entry_id'] ?? ''] ?? null;
            if ($entry === null || $entry['kind'] !== 'file' || $upload === null || $upload['state'] !== 'published'
                || $upload['storage_key'] !== $version['storage_key'] || $upload['entry_id'] !== $version['entry_id']
                || $upload['project_id'] !== $entry['project_id']) {
                $conflict((string) $version['storage_key']);
            }
            $classes[] = ['key' => $version['storage_key'], 'classification' => 'committed', 'version_id' => $version['id']];
        }
        foreach ($uploads as $upload) {
            $key = (string) $upload['storage_key'];
            $references = array_values(array_filter($versions, static fn (array $version): bool => $version['storage_key'] === $key));
            $cleanup = $cleanups[$key] ?? null;
            $classification = $upload['state'];
            if ($upload['state'] === 'published') {
                if (count($references) === 1 && $references[0]['upload_id'] === $upload['id'] && $cleanup === null) {
                    $classification = 'live_publication';
                } elseif ($references === [] && $cleanup !== null && $cleanup['retained_fence'] === 0) {
                    $classification = 'pending_removal_handoff';
                } else {
                    $conflict($key);
                }
            } elseif ($upload['state'] === 'abandoned') {
                if ($references !== [] || $cleanup === null || $cleanup['retained_fence'] !== 1) {
                    $conflict($key);
                }
            } elseif ($upload['state'] === 'active') {
                if ($references !== [] || $cleanup !== null) {
                    $conflict($key);
                }
            } else {
                $conflict($key);
            }
            $classes[] = ['key' => $key, 'classification' => $classification, 'upload_id' => $upload['id'], 'tombstone_id' => $cleanup['id'] ?? null];
        }
        foreach ($cleanups as $key => $cleanup) {
            $matching = array_values(array_filter($uploads, static fn (array $upload): bool => $upload['storage_key'] === $key));
            if ($cleanup['retained_fence'] === 1 && (count($matching) !== 1 || $matching[0]['state'] !== 'abandoned')) {
                $conflict((string) $key);
            }
            if (array_filter($versions, static fn (array $version): bool => $version['storage_key'] === $key) !== []
                || array_filter($matching, static fn (array $upload): bool => $upload['state'] === 'active') !== []) {
                $conflict((string) $key);
            }
            $classes[] = ['key' => $key, 'classification' => $cleanup['retained_fence'] === 1 ? 'retained_fence' : 'removal_tombstone', 'tombstone_id' => $cleanup['id']];
        }

        return [$differences, $classes];
    }

    private function fingerprint(mixed $inventory): string
    {
        // Associative field names are represented as fixed-order pairs in canonical JSON arrays.
        return hash('sha256', json_encode($this->canonical($inventory), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->canonical(...), $value);
        }
        $pairs = [];
        foreach ($value as $key => $item) {
            $pairs[] = [$key, $this->canonical($item)];
        }

        return $pairs;
    }

    private function changed(): ResourceOperationException
    {
        return new ResourceOperationException('project_documents.cleanup_inventory_changed', 'Inventory changed; reconcile again.', 409);
    }

    private function provider(): ResourceOperationException
    {
        return new ResourceOperationException('project_documents.storage_unavailable', 'Document storage is unavailable.', 503);
    }
}
