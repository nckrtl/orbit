<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\DatabaseConnectionDoctorInspection;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorNodeContext;
use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use Illuminate\Database\Eloquent\Collection;

final readonly class DatabaseConnectionDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private DatabaseConnectionDoctorInspection $inspection,
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
            $issues = [...$issues, ...$this->inspection->attachment($attachment)];
        }

        foreach ($unattached as $connection) {
            $issue = $this->inspection->connection($connection);

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
}
