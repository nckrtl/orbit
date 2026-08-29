<?php

declare(strict_types=1);

namespace App\Commands\Doctor;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Doctor\RunDoctorRequest;
use Orbit\Sdk\Responses\Doctor\DoctorReportResponse;

final class DoctorCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'doctor
        {--node= : Numeric node ID; omit to check all registered nodes}
        {--family=* : Limit checks to node, role, app, instance, workspace, tool, process, or firewall}
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

        $families = $this->option('family');
        $families = is_array($families) && $families !== [] ? array_values(array_map(strval(...), $families)) : null;
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }

        $report = $this->send($connector, new RunDoctorRequest($nodeId, $families), DoctorReportResponse::class);
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
                    $rows[] = [$nodeReport->nodeName, $family->family, $family->status, $family->checked, '—'];
                    continue;
                }
                foreach ($issues as $issue) {
                    $rows[] = [
                        $nodeReport->nodeName,
                        $family->family,
                        $family->status,
                        $family->checked,
                        "{$issue->code}: {$issue->summary}",
                    ];
                }
            }
        }
        $this->table(['Node', 'Family', 'Status', 'Checked', 'Finding'], $rows);
        $this->line('Healthy: '.($report->healthy ? 'yes' : 'no'));
        $this->line("Request ID: {$report->requestId}");

        return $report->healthy ? self::SUCCESS : self::FAILURE;
    }
}
