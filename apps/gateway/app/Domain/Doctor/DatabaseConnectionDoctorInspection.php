<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Data\Doctor\DoctorIssueData;
use App\Domain\DatabaseConnections\DatabaseConnectionEnvProjection;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Models\AppInstanceEnvironmentValue;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use Illuminate\Contracts\Encryption\DecryptException;

final readonly class DatabaseConnectionDoctorInspection
{
    public function __construct(
        private DatabaseConnectionEnvProjection $projection,
    ) {}

    /** @return list<DoctorIssueData> */
    public function attachment(DatabaseConnectionTarget $attachment): array
    {
        $connection = DatabaseConnection::query()->find($attachment->database_connection_id);

        if (! $connection instanceof DatabaseConnection) {
            return [$this->issue(
                DatabaseConnectionDoctorIssueCode::Missing,
                DoctorIssueKind::Drift,
                $attachment->id,
                $this->resourceName($attachment, null),
                'The attachment names a registry connection that is not present.',
                'present',
                'absent',
            )];
        }

        $connectionIssue = $this->connection($connection, $attachment);

        if ($connectionIssue instanceof DoctorIssueData) {
            return [$connectionIssue];
        }

        try {
            $stored = $this->storedValues($attachment->app_instance_id);
            $projected = $this->projection->project($connection, $attachment->appInstance, $attachment->prefix);
        } catch (DecryptException) {
            return [$this->issue(
                DatabaseConnectionDoctorIssueCode::InspectionFailed,
                DoctorIssueKind::Unverifiable,
                $attachment->id,
                $this->resourceName($attachment, $connection),
                'Database connection stored environment could not be verified.',
                'verifiable',
                'unverifiable',
            )];
        }

        if ($this->projection->driftedKeys($stored, $projected) === []) {
            return [];
        }

        return [$this->issue(
            DatabaseConnectionDoctorIssueCode::EnvMismatch,
            DoctorIssueKind::Drift,
            $attachment->id,
            $this->resourceName($attachment, $connection),
            'Attached stored environment keys do not match the registry projection.',
            'matching',
            'mismatch',
        )];
    }

    public function connection(
        DatabaseConnection $connection,
        ?DatabaseConnectionTarget $attachment = null,
    ): ?DoctorIssueData {
        try {
            $unhealthy = $this->unhealthy($connection);
        } catch (DecryptException) {
            return $this->issue(
                DatabaseConnectionDoctorIssueCode::InspectionFailed,
                DoctorIssueKind::Unverifiable,
                $this->resourceId($connection, $attachment),
                $this->resourceName($attachment, $connection),
                'Database connection inspection could not be verified.',
                'verifiable',
                'unverifiable',
            );
        }

        if (! $unhealthy) {
            return null;
        }

        return $this->issue(
            DatabaseConnectionDoctorIssueCode::Unhealthy,
            DoctorIssueKind::Drift,
            $this->resourceId($connection, $attachment),
            $this->resourceName($attachment, $connection),
            'The registry connection is missing required fields or its associated Node.',
            'usable',
            'unhealthy',
        );
    }

    private function unhealthy(DatabaseConnection $connection): bool
    {
        $password = $connection->password;

        if ($connection->node_id !== null && ! Node::query()->whereKey($connection->node_id)->exists()) {
            return true;
        }

        if ($connection->driver === DatabaseDriver::Sqlite) {
            return ! is_string($connection->path) || $connection->path === '';
        }

        if (! $connection->driver->requiresCredentials()) {
            return ! is_string($connection->host) || $connection->host === '' || $connection->port === null;
        }

        return ! is_string($connection->host)
            || $connection->host === ''
            || $connection->port === null
            || ! is_string($connection->database)
            || $connection->database === ''
            || ! is_string($connection->username)
            || $connection->username === ''
            || ! is_string($password);
    }

    /** @return array<string, string> */
    private function storedValues(int $appInstanceId): array
    {
        return AppInstanceEnvironmentValue::query()
            ->where('app_instance_id', $appInstanceId)
            ->orderBy('env_key')
            ->get()
            ->mapWithKeys(static fn (AppInstanceEnvironmentValue $row): array => [$row->env_key => $row->env_value])
            ->all();
    }

    private function resourceName(
        ?DatabaseConnectionTarget $attachment,
        ?DatabaseConnection $connection,
    ): string {
        $slug = $connection instanceof DatabaseConnection ? $connection->slug : 'missing';

        if (! $attachment instanceof DatabaseConnectionTarget) {
            return $slug;
        }

        return $slug.':'.$attachment->prefix;
    }

    private function resourceId(
        DatabaseConnection $connection,
        ?DatabaseConnectionTarget $attachment,
    ): int {
        if ($attachment instanceof DatabaseConnectionTarget) {
            return $attachment->id;
        }

        return $connection->id;
    }

    private function issue(
        DatabaseConnectionDoctorIssueCode $code,
        DoctorIssueKind $kind,
        int $resourceId,
        string $resourceName,
        string $summary,
        string $expected,
        string $observed,
    ): DoctorIssueData {
        return new DoctorIssueData(
            $code,
            $kind,
            DoctorFamily::DatabaseConnection->value,
            $resourceId,
            $resourceName,
            $summary,
            $expected,
            $observed,
        );
    }
}
