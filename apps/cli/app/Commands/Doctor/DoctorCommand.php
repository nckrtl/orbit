<?php

declare(strict_types=1);

namespace App\Commands\Doctor;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\ProgressState;
use Orbit\Sdk\Requests\Doctor\RunDoctorRequest;
use Orbit\Sdk\Responses\Doctor\DoctorReportResponse;

final class DoctorCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'doctor
        {--node= : Numeric node ID; omit to check all registered nodes}
        {--family=* : Limit checks to node, role, app, instance, schedule, tool, process, firewall, herdr, database_connection, or route}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Verify registered node state without making repairs.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $node = $this->option('node');
        $nodeId = null;
        if ($node !== null) {
            $nodeId = filter_var($node, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($nodeId)) {
                return $this->renderGatewayFailure('doctor.node_id_invalid', 'Node ID must be a positive integer.');
            }
        }

        $families = $this->input->getOption('family');
        $families = is_array($families) && $families !== [] ? array_values(array_map(strval(...), $families)) : null;
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }

        $report = $this->sendWithProgress(
            $connector,
            new RunDoctorRequest($nodeId, $families),
            DoctorReportResponse::class,
            ['Verify registered state', 'Verifying registered state', 'Verified registered state'],
            static fn (DoctorReportResponse $response): ProgressState => $response->healthy
                ? ProgressState::Success
                : ProgressState::Warning,
        );
        if (! $report instanceof DoctorReportResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($report->toArray());

            return $report->healthy ? self::SUCCESS : self::FAILURE;
        }

        $rows = [];
        foreach ($report->nodes as $nodeReport) {
            foreach ($nodeReport->families as $family) {
                $issues = $family->issues;
                if ($issues === []) {
                    $rows[] = [$nodeReport->nodeName, $family->family, $family->status, $family->checked, '—', '—'];

                    continue;
                }
                foreach ($issues as $issue) {
                    $rows[] = [
                        $nodeReport->nodeName,
                        $family->family,
                        $family->status,
                        $family->checked,
                        $this->doctorResourceLabel($issue->resourceType, $issue->resourceId, $issue->resourceName),
                        "{$issue->code}: {$issue->summary}".$this->doctorExpectedObserved($issue->expected, $issue->observed),
                    ];
                }
            }
        }
        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Node', 'Family', 'Status', 'Checked', 'Resource', 'Finding'],
            $rows,
        ));
        $this->writeHumanMessage('Healthy: '.($report->healthy ? 'yes' : 'no'));
        $this->writeHumanMessage("Request ID: {$report->requestId}");

        return $report->healthy ? self::SUCCESS : self::FAILURE;
    }

    private function doctorResourceLabel(string $resourceType, int|string|null $resourceId, ?string $resourceName): string
    {
        $identity = $resourceName ?? ($resourceId !== null ? "#{$resourceId}" : null);

        return $identity === null ? $resourceType : "{$resourceType} {$identity}";
    }

    private function doctorExpectedObserved(bool|string|null $expected, bool|string|null $observed): string
    {
        if ($expected === null && $observed === null) {
            return '';
        }

        return ' (expected: '.$this->doctorFindingValue($expected).', observed: '.$this->doctorFindingValue($observed).')';
    }

    private function doctorFindingValue(bool|string|null $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'yes' : 'no',
            default => $value,
        };
    }
}
