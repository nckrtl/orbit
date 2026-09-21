<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Responses\Analytics\InstanceAnalyticsResponse;

/** What the three `instance:analytics:*` commands share: one answer, rendered one way. */
abstract class InstanceAnalyticsCommand extends GatewayCommand
{
    protected function renderAnalytics(InstanceAnalyticsResponse $response, string $headline): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($headline, [
            'Instance' => "#{$response->instanceId}",
            'Domain' => $response->domain,
            'Tracking' => $response->enabled ? 'enabled' : 'disabled',
            'Dashboard' => $response->dashboardUrl,
            'Request ID' => $response->requestId,
        ]));

        if ($response->hosts === []) {
            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Host', 'Route', 'Status', 'Publication'],
            array_map(static fn (array $host): array => [
                $host['host'],
                (string) $host['route_id'],
                $host['error_code'] === null ? $host['status'] : "{$host['status']} ({$host['error_code']})",
                $host['publication'],
            ], $response->hosts),
        ));

        // Whole lines, not table cells: a host name is long, and a wrapped DNS record is hard to copy.
        $records = array_filter($response->hosts, static fn (array $host): bool => $host['dns'] !== null);

        if ($records !== []) {
            $this->writeHumanMessage('Create these DNS records:');

            foreach ($records as $host) {
                $this->writeHumanMessage("  {$host['dns']['type']} {$host['dns']['name']} -> {$host['dns']['value']}");
            }
        }

        if ($response->snippet !== null) {
            $this->writeHumanMessage('Add this tag to the App, and create the site in Plausible yourself:');
            $this->writeHumanMessage($response->snippet);
        }

        return self::SUCCESS;
    }
}
