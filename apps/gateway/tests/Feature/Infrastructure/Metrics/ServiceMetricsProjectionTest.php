<?php

declare(strict_types=1);

use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Infrastructure\Metrics\PrometheusConfigRenderer;
use App\Infrastructure\Metrics\ServiceMetricsConfigRenderer;
use App\Infrastructure\Metrics\ServiceMetricsNode;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Symfony\Component\Yaml\Yaml;

it('combines metrics preferences with applicable roles', function (): void {
    $metrics = service_metrics_node('metrics');
    $metrics->roles()->create(['role' => 'metrics', 'status' => 'active']);
    $node = service_metrics_node('workload');
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $cluster = Cluster::query()->create(['name' => 'production']);
    $node->update(['cluster_id' => $cluster->id]);
    $node->roles()->create(['role' => 'ingress', 'status' => 'provisioning', 'cluster_id' => $cluster->id]);
    $projection = app(ServiceMetricsProjection::class);
    $selected = $projection->forNode($metrics, $node);
    expect([$selected->caddy, $selected->fpm])->toBe([false, true]);
    $node->roles()->where('role', 'ingress')->update(['status' => 'active']);
    $instance = service_metrics_instance($node, 'public-app', '8.5');
    $route = Route::query()->create([
        'app_id' => $instance->app_id, 'cluster_id' => $cluster->id,
        'domain' => 'app.example.test', 'provenance' => 'explicit',
        'publication' => 'private', 'status' => 'pending',
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['publication' => 'public', 'public_publication' => 'active', 'status' => 'active']);
    $published = $projection->forNode($metrics, $node);
    expect($published->caddy)->toBeTrue();
    expect($published->hosts)->toBe(['app.example.test']);
    app(ExporterPreferenceRepository::class)->put($node->id, ExporterPreference::Disabled);
    $disabled = $projection->forNode($metrics, $node);
    expect([$disabled->caddy, $disabled->fpm])->toBe([false, false]);
});

it('excludes shared, non-PHP and inactive runtimes', function (): void {
    $metrics = service_metrics_node('metrics');
    $metrics->roles()->create(['role' => 'metrics', 'status' => 'active']);
    $node = service_metrics_node('workload');
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $dedicated = service_metrics_instance($node, 'dedicated', '8.5');
    $shared = service_metrics_instance($node, 'shared', '8.4');
    $shared->update(['production_php_service' => null, 'production_php_pool' => null, 'production_php_socket' => null]);
    $removing = service_metrics_instance($node, 'removing', '8.4');
    $removing->update(['status' => 'reserved']);
    $static = service_metrics_instance($node, 'static', '8.5');
    $static->update(['selected_php_version' => null, 'production_php_service' => null]);
    $target = app(ServiceMetricsProjection::class)->forNode($metrics, $node);
    expect(array_column($target->instances, 'id'))->toBe([$dedicated->id]);
});

it('configures explicit multi-version pools without application commands', function (): void {
    $node = service_metrics_node('workload');
    $first = service_metrics_instance($node, 'first', '8.4');
    $second = service_metrics_instance($node, 'second', '8.5');
    $target = new ServiceMetricsNode($node, false, true, [$first, $second]);
    $config = Yaml::parse(new ServiceMetricsConfigRenderer()->fpm($target));
    expect($config['phpfpm']['autodiscover'])->toBeFalse();
    expect($config['laravel'])->toBe([]);
    expect(array_column($config['phpfpm']['pools'], 'binary'))->toBe(['/usr/sbin/php-fpm8.4', '/usr/sbin/php-fpm8.5']);
    expect($config['phpfpm']['pools'][0]['status_socket'])->toBe('unix://'.$first->production_php_socket.'.status');
    $prometheus = Yaml::parse(new PrometheusConfigRenderer()->render([], [$target]));
    $job = $prometheus['scrape_configs'][2];
    expect($job['static_configs'][0]['targets'])->toBe([$node->wireguard_ip.':9114']);
    expect($job['metric_relabel_configs'][1]['replacement'])->toBe((string) $first->id);
    expect($job['metric_relabel_configs'][0])->toBe(['source_labels' => ['__name__'], 'regex' => 'phpfpm_process_.*', 'action' => 'drop']);
});

it('publishes no FPM scrape target for an empty node', function (): void {
    $node = service_metrics_node('empty');
    $config = Yaml::parse(new PrometheusConfigRenderer()->render([], [new ServiceMetricsNode($node, false, true)]));
    expect($config['scrape_configs'])->toHaveCount(2);
});

function service_metrics_node(string $name): Node
{
    return Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.81', 'wireguard_ip' => '10.44.0.'.(Node::query()->count() + 10), 'ssh_host_fingerprint' => 'SHA256:metrics-proof']);
}

function service_metrics_instance(Node $node, string $name, string $version): AppInstance
{
    $app = OrbitApp::query()->create(['name' => $name, 'slug' => $name, 'repository_url' => 'https://example.test/'.$name.'.git', 'default_branch' => 'main', 'root' => 'public']);
    $user = 'orbit-app-'.$app->id;
    $instance = AppInstance::query()->create(['app_id' => $app->id, 'node_id' => $node->id, 'name' => 'default', 'environment' => 'production', 'status' => 'active', 'checkout_path' => '/home/'.$user, 'production_user' => $user, 'production_home' => '/home/'.$user, 'root' => 'public', 'selected_php_version' => $version]);
    $instance->update(ProductionPhpRuntimeIdentity::forProvisioning($instance, $version)->attributes());

    return $instance->refresh();
}
