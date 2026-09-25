<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use JsonException;

final readonly class NativeServiceMetricsRuntime implements ServiceMetricsRuntime
{
    public function __construct(
        private AppProdSshExecutor $ssh,
        private NodeCaddyBuilds $builds,
        private ServiceMetricsConfigRenderer $renderer = new ServiceMetricsConfigRenderer,
    ) {}

    public function snapshot(ServiceMetricsNode $target): string
    {
        $state = $this->program($target->node, ['operation' => 'snapshot']);
        $pools = [];
        foreach ($target->instances as $instance) {
            $identity = ProductionPhpRuntimeIdentity::from($instance);
            $pools[(string) $instance->id] = $this->pool($target->node, $identity, ['operation' => 'snapshot']);
        }

        return json_encode(['exporter' => $state, 'pools' => $pools], JSON_THROW_ON_ERROR);
    }

    public function converge(ServiceMetricsNode $target, Node $metricsNode): void
    {
        $fingerprints = '';
        foreach ($target->instances as $instance) {
            $state = $this->pool($target->node, ProductionPhpRuntimeIdentity::from($instance), ['operation' => 'apply', 'enabled' => $target->fpm]);
            $fingerprints .= '# runtime '.$instance->id.': '.$state['fingerprint']."\n";
        }
        $fpm = $target->fpm && $target->instances !== [];
        $rules = [];
        foreach (['caddy' => $target->caddy, 'fpm' => $fpm] as $kind => $enabled) {
            if (! $enabled) {
                continue;
            }
            $port = $kind === 'caddy' ? ServiceMetricsConfigRenderer::CaddyPort : ServiceMetricsConfigRenderer::FpmPort;
            foreach (['allow', 'deny'] as $action) {
                $rules[] = [
                    $action, 'in', 'on', 'orbit', 'proto', 'tcp',
                    'from', $action === 'allow' ? (string) $metricsNode->wireguard_ip : 'any',
                    'to', (string) $target->node->wireguard_ip, 'port', $port,
                    'comment', 'orbit:metrics-service-'.$kind.'-'.$action,
                ];
            }
        }
        $this->program($target->node, ['operation' => 'apply', 'state' => [
            'config' => $fpm ? $this->renderer->fpm($target).$fingerprints : null,
            'unit' => $fpm ? $this->renderer->unit() : null,
            'rules' => $rules,
            'active' => $fpm,
            'enabled' => $fpm,
            'binary' => $fpm,
        ]]);
        $this->build($target->node);
    }

    public function restore(ServiceMetricsNode $target, string $snapshot): void
    {
        $state = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
        foreach ($target->instances as $instance) {
            $this->pool($target->node, ProductionPhpRuntimeIdentity::from($instance), ['operation' => 'restore', 'state' => $state['pools'][(string) $instance->id]]);
        }
        $this->program($target->node, ['operation' => 'apply', 'state' => $state['exporter']]);
        $this->build($target->node);
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function pool(Node $node, ProductionPhpRuntimeIdentity $identity, array $request): array
    {
        $program = file_get_contents(resource_path('scripts/service-metrics-fpm.py'));
        if (! is_string($program)) {
            throw new ResourceOperationException('metrics.service_program_missing', 'FPM monitoring program is unavailable.', 500);
        }
        $request += ['user' => $identity->user, 'version' => $identity->version, 'marker' => $identity->marker()];
        $result = $this->ssh->execute($node, new RemoteCommand(
            ['sudo', 'python3', '-', base64_encode(json_encode($request, JSON_THROW_ON_ERROR))],
            input: $program,
            maxOutputBytes: 524288,
        ), 'service-metrics-fpm', 'metrics.fpm_monitoring_failed');
        if ($result->truncated) {
            throw new ResourceOperationException('metrics.service_inspection_failed', 'FPM monitoring inspection was truncated.', 502);
        }

        return json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The scrape site renders from stored state (ADR 0141): the selected Metrics Node and the Node's
     * `ingress` role and public Routes. The build reads that state; this runtime never writes Caddy files.
     * Only an Ingress Node can render the site, so no other Node is built.
     */
    private function build(Node $node): void
    {
        if (! CaddySiteRoles::nodeServes($node->id, RoleName::Ingress)) {
            return;
        }

        try {
            $this->builds->build($node);
        } catch (NodeCaddyBuildException $exception) {
            throw new RuntimeConvergenceException(
                step: 'service-metrics-caddy',
                errorCode: 'metrics.caddy_publication_failed',
                message: $exception->getMessage(),
                previous: $exception,
                result: $exception->result(),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function program(Node $node, array $request): array
    {
        $program = file_get_contents(resource_path('scripts/service-metrics.py'));
        if (! is_string($program)) {
            throw new ResourceOperationException('metrics.service_program_missing', 'Service metrics program is unavailable.', 500);
        }
        $result = $this->ssh->execute($node, new RemoteCommand(
            ['sudo', 'python3', '-', base64_encode(json_encode($request, JSON_THROW_ON_ERROR))],
            input: $program,
            maxOutputBytes: 524288,
            timeout: 180,
        ), 'service-metrics', 'metrics.service_convergence_failed');
        if ($request['operation'] !== 'snapshot') {
            return [];
        }
        if ($result->truncated) {
            throw new ResourceOperationException('metrics.service_inspection_failed', 'Service metrics inspection was truncated.', 502);
        }
        try {
            $state = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ResourceOperationException('metrics.service_inspection_failed', 'Service metrics inspection returned invalid data.', 502, $exception);
        }
        if (! is_array($state)) {
            throw new ResourceOperationException('metrics.service_inspection_failed', 'Service metrics inspection returned invalid state.', 502);
        }

        return $state;
    }
}
