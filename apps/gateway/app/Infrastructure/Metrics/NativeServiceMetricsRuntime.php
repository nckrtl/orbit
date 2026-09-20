<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppProd\AppProdCaddyPublisher;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use JsonException;

final readonly class NativeServiceMetricsRuntime implements ServiceMetricsRuntime
{
    private const string CaddyFragment = '00-metrics-service.caddy';

    public function __construct(
        private AppProdSshExecutor $ssh,
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
        $result = $this->ssh->execute($target->node, new RemoteCommand(
            ['sudo', 'python3', '-'],
            input: <<<'PY'
                import base64, json, pathlib, sys
                result = {'caddy': None}
                main = pathlib.Path('/etc/caddy/Caddyfile')
                if main.exists():
                    fragment = main.resolve().parent / 'fragments/00-metrics-service.caddy'
                    if fragment.is_symlink():
                        raise ValueError('Unowned Caddy fragment')
                    if fragment.exists():
                        contents = fragment.read_text()
                        if fragment.stat().st_uid != 0 or not contents.startswith('# Managed by Orbit: service-metrics\n'):
                            raise ValueError('Unowned Caddy fragment')
                        result['caddy'] = contents
                print(json.dumps(result))
                PY,
            maxOutputBytes: 524288,
        ), 'service-metrics-inspect', 'metrics.service_inspection_failed');
        if ($result->truncated) {
            throw new ResourceOperationException('metrics.service_inspection_failed', 'Service metrics inspection was truncated.', 502);
        }
        $extra = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);

        return json_encode(['exporter' => $state, 'pools' => $pools, ...$extra], JSON_THROW_ON_ERROR);
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
        $this->publishCaddy($target->node, $target->caddy
            ? $this->renderer->caddy((string) $target->node->wireguard_ip, (string) $metricsNode->wireguard_ip, $this->perHost($target->node))
            : null);
    }

    public function restore(ServiceMetricsNode $target, string $snapshot): void
    {
        $state = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
        foreach ($target->instances as $instance) {
            $this->pool($target->node, ProductionPhpRuntimeIdentity::from($instance), ['operation' => 'restore', 'state' => $state['pools'][(string) $instance->id]]);
        }
        $this->program($target->node, ['operation' => 'apply', 'state' => $state['exporter']]);
        $this->publishCaddy($target->node, $state['caddy']);
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

    private function publishCaddy(Node $node, ?string $configuration): void
    {
        $publisher = new AppProdCaddyPublisher(ownedFragment: self::CaddyFragment, ownershipMarker: ServiceMetricsConfigRenderer::Marker);
        $version = bin2hex(random_bytes(8));
        $command = $configuration === null ? $publisher->removeCommand($version) : $publisher->command($configuration, $version);
        $this->ssh->execute($node, $command, 'service-metrics-caddy', 'metrics.caddy_publication_failed');
    }

    private function perHost(Node $node): bool
    {
        $result = $this->ssh->execute($node, new RemoteCommand(['caddy', 'version']), 'service-metrics-caddy-version', 'metrics.caddy_version_failed');
        if (! preg_match('/\Av?(\d+\.\d+\.\d+)(?:\s|$)/', trim($result->stdout), $matches)) {
            throw new ResourceOperationException('metrics.caddy_version_failed', 'Caddy returned an unsupported version string.', 502);
        }

        return version_compare($matches[1], '2.9.0', '>=');
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
