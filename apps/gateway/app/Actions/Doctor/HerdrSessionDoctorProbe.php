<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\HerdrSessionDoctorIssueCode;
use App\Domain\Herdr\HerdrSessionHealth;
use App\Models\HerdrSession;

final readonly class HerdrSessionDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private HerdrSessionHealth $health,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::Herdr;
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $sessions = HerdrSession::query()
            ->with('process')
            ->where('node_id', $context->node->id)
            ->orderBy('id')
            ->get();

        if ($sessions->isEmpty()) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Herdr, 0, []);
        }

        if (! $context->inspection->reachable) {
            return DoctorFamilyReportData::fromIssues(
                DoctorFamily::Herdr,
                $sessions->count(),
                [new DoctorIssueData(
                    HerdrSessionDoctorIssueCode::NodeUnreachable,
                    DoctorIssueKind::Unverifiable,
                    'herdr',
                    null,
                    null,
                    'Herdr session state cannot be inspected because the node is unreachable.',
                    'reachable',
                    'unreachable',
                )],
            );
        }

        $issues = [];

        foreach ($sessions as $session) {
            try {
                $health = $this->health->inspect($session);
            } catch (\Throwable) {
                $issues[] = new DoctorIssueData(
                    HerdrSessionDoctorIssueCode::InspectionFailed,
                    DoctorIssueKind::Unverifiable,
                    'herdr',
                    $session->id,
                    $session->session,
                    "Herdr session [{$session->session}] could not be inspected.",
                    'verifiable',
                    'unverifiable',
                );

                continue;
            }

            $issues = [
                ...$issues,
                ...$this->issuesFor($session, $health),
            ];
        }

        return DoctorFamilyReportData::fromIssues(DoctorFamily::Herdr, $sessions->count(), $issues);
    }

    /**
     * @param  array{process: string, listener: string, session: string}  $health
     * @return list<DoctorIssueData>
     */
    private function issuesFor(HerdrSession $session, array $health): array
    {
        $issues = [];

        if (! in_array($health['process'], ['healthy', 'external'], true)) {
            $issues[] = $this->issue(
                $session,
                HerdrSessionDoctorIssueCode::ProcessUnhealthy,
                'Process ['.$session->session.'] does not match its managed Herdr runtime state.',
                'healthy',
                $health['process'],
            );
        }

        if ($health['listener'] !== 'healthy') {
            $issues[] = $this->issue(
                $session,
                HerdrSessionDoctorIssueCode::ListenerUnhealthy,
                'Observer for Herdr session ['.$session->session.'] is unpublished or failed.',
                'healthy',
                $health['listener'],
            );
        }

        if ($health['session'] !== 'healthy') {
            $issues[] = $this->issue(
                $session,
                HerdrSessionDoctorIssueCode::SessionUnhealthy,
                'Herdr session ['.$session->session.'] identity does not match stored session health.',
                'healthy',
                $health['session'],
            );
        }

        return $issues;
    }

    private function issue(
        HerdrSession $session,
        HerdrSessionDoctorIssueCode $code,
        string $summary,
        string $expected,
        string $observed,
    ): DoctorIssueData {
        return new DoctorIssueData(
            $code,
            DoctorIssueKind::Drift,
            'herdr',
            $session->id,
            $session->session,
            $summary,
            $expected,
            $observed,
        );
    }
}
