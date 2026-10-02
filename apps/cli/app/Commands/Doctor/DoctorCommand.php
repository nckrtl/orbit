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
        {--family=* : Limit checks to node, role, project, instance, schedule, tool, process, firewall, database_connection, or route}
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

        $families = $this->familyNames();
        if ($families === false) {
            return self::FAILURE;
        }
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
                        $this->doctorResourceLabel(
                            $issue->code,
                            $issue->resourceType,
                            $issue->resourceId,
                            $issue->resourceName,
                            $issue->expected,
                        ),
                        "{$issue->code}: {$issue->summary}".$this->doctorExpectedObserved($issue->expected, $issue->observed),
                    ];
                }
            }
        }
        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Node', 'Family', 'Status', 'Checked', 'Resource', 'Finding'],
            $rows,
        ));
        $this->writeHumanMessage(sprintf(
            'Nodes: %d, families: %d, checks: %d, drift: %d, unverifiable: %d, informational: %d',
            $report->summary['nodes'], $report->summary['families'], $report->summary['checks'],
            $report->summary['drift'], $report->summary['unverifiable'], $report->summary['informational'],
        ));
        $this->writeHumanMessage('Healthy: '.($report->healthy ? 'yes' : 'no'));
        $this->writeHumanMessage("Request ID: {$report->requestId}");

        return $report->healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Family options are names. Scalars keep the previous string cast; a list or object is not a name.
     *
     * @return list<string>|false|null
     */
    private function familyNames(): array|false|null
    {
        $families = $this->input->getOption('family');

        if (! is_array($families) || $families === []) {
            return null;
        }

        $names = [];

        foreach ($families as $family) {
            if (is_string($family) || is_int($family) || is_float($family) || is_bool($family) || $family === null || $family instanceof \GMP || is_resource($family)) {
                $names[] = strval($family);

                continue;
            }

            if ($family instanceof \Stringable) {
                $names[] = (string) $family;

                continue;
            }

            $this->renderGatewayFailure('doctor.family_invalid', 'Family must be a string.');

            return false;
        }

        return $names;
    }

    private function doctorResourceLabel(
        string $code,
        string $resourceType,
        int|string|null $resourceId,
        ?string $resourceName,
        bool|string|null $expected,
    ): string {
        if ($code === 'tool.package_unregistered' && is_string($expected) && is_string($resourceName) && $resourceName !== '') {
            return "tool {$expected} {$resourceName}";
        }

        if ($code === 'tool.inventory_scan' && is_string($resourceName) && $resourceName !== '') {
            return "tool {$resourceName}";
        }

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
