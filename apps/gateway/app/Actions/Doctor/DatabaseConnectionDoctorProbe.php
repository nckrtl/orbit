<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\DatabaseConnections\DatabaseConnectionEnvProjection;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Doctor\DatabaseConnectionDoctorIssueCode;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;

final readonly class DatabaseConnectionDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private DatabaseConnectionEnvProjection $projection,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::DatabaseConnection;
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $attachments = $this->attachmentsOn($context->node);
        $unattached = $this->unattachedNodeConnections($context->node, $attachments);
        $checked = $attachments->count() + $unattached->count();

        if ($checked === 0) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::DatabaseConnection, 0, []);
        }

        $issues = [];

        foreach ($attachments as $attachment) {
            $issues = [...$issues, ...$this->attachmentIssues($attachment)];
        }

        foreach ($unattached as $connection) {
            $issue = $this->connectionIssue($connection);

            if ($issue instanceof DoctorIssueData) {
                $issues[] = $issue;
            }
        }

        return DoctorFamilyReportData::fromIssues(DoctorFamily::DatabaseConnection, $checked, $issues);
    }

    /** @return Collection<int, DatabaseConnectionTarget> */
    private function attachmentsOn(Node $node): Collection
    {
        return DatabaseConnectionTarget::query()
            ->with(['databaseConnection', 'appInstance'])
            ->whereIn(
                'app_instance_id',
                AppInstance::query()->select('id')->where('node_id', $node->id),
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, DatabaseConnectionTarget>  $attachments
     * @return Collection<int, DatabaseConnection>
     */
    private function unattachedNodeConnections(Node $node, Collection $attachments): Collection
    {
        $attachedIds = $attachments
            ->pluck('database_connection_id')
            ->filter(static fn (mixed $id): bool => is_int($id))
            ->all();

        return DatabaseConnection::query()
            ->where('node_id', $node->id)
            ->when(
                $attachedIds !== [],
                static fn ($query) => $query->whereNotIn('id', $attachedIds),
            )
            ->orderBy('id')
            ->get();
    }

    /** @return list<DoctorIssueData> */
    private function attachmentIssues(DatabaseConnectionTarget $attachment): array
    {
        $connection = $attachment->databaseConnection;

        if (! $connection instanceof DatabaseConnection) {
            return [$this->issue(
                DatabaseConnectionDoctorIssueCode::Missing,
                DoctorIssueKind::Drift,
                $attachment->id,
                $this->attachmentName($attachment, null),
                'The attachment names a registry connection that is not present.',
                'present',
                'absent',
            )];
        }

        $connectionIssue = $this->connectionIssue($connection, $attachment);

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
                $this->attachmentName($attachment, $connection),
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
            $this->attachmentName($attachment, $connection),
            'Attached stored environment keys do not match the registry projection.',
            'matching',
            'mismatch',
        )];
    }

    private function connectionIssue(
        DatabaseConnection $connection,
        ?DatabaseConnectionTarget $attachment = null,
    ): ?DoctorIssueData {
        try {
            $unhealthy = $this->unhealthy($connection);
        } catch (DecryptException) {
            return $this->issue(
                DatabaseConnectionDoctorIssueCode::InspectionFailed,
                DoctorIssueKind::Unverifiable,
                $attachment?->id ?? $connection->id,
                $this->attachmentName($attachment, $connection),
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
            $attachment?->id ?? $connection->id,
            $this->attachmentName($attachment, $connection),
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

    private function attachmentName(
        ?DatabaseConnectionTarget $attachment,
        ?DatabaseConnection $connection,
    ): string {
        $slug = $connection?->slug ?? 'missing';

        if (! $attachment instanceof DatabaseConnectionTarget) {
            return $slug;
        }

        return $slug.':'.$attachment->prefix;
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
