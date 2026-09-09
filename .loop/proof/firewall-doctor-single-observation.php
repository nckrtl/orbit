<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsFirewallExpectationProvider;
use App\Domain\Shared\LifecycleStatus;
use App\Models\FirewallRule;
use App\Models\Node;
use Illuminate\Contracts\Console\Kernel;

require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';
$laravel = require '/home/orbit/orbit/apps/gateway/bootstrap/app.php';
$laravel->make(Kernel::class)->bootstrap();

$command = $argv[1] ?? '';
$fixtureNames = ['orb174-first', 'orb174-second', 'orb174-lifecycle'];

set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, "ORB-174 fixture failed: {$exception->getMessage()}\n");
    exit(70);
});

/** @param array<string, mixed> $value */
function writeJson(array $value): void
{
    echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
}

function appDevNode(): Node
{
    return Node::query()
        ->where('name', 'app-dev')
        ->where('status', LifecycleStatus::Active->value)
        ->sole();
}

/** @param list<string> $fixtureNames */
function fixtureState(Node $node, array $fixtureNames): array
{
    $rules = FirewallRule::query()
        ->where('node_id', $node->id)
        ->whereIn('name', $fixtureNames)
        ->orderBy('id')
        ->get();
    $indexed = [];

    foreach ($rules as $rule) {
        $indexed[$rule->name] = [
            'id' => $rule->id,
            'status' => $rule->status->value,
            'comment' => "orbit:node:{$node->id}:firewall:{$rule->name}",
            'port' => $rule->port,
        ];
    }

    $expectations = app(MetricsFirewallExpectationProvider::class)->for($node);

    return [
        'node' => [
            'id' => $node->id,
            'wireguard_ip' => $node->wireguard_ip,
        ],
        'rules' => $indexed,
        'synthetic' => array_map(
            static fn ($target): int|string => $target->resourceId,
            $expectations,
        ),
    ];
}

$node = appDevNode();

switch ($command) {
    case 'setup':
        FirewallRule::query()
            ->where('node_id', $node->id)
            ->whereIn('name', $fixtureNames)
            ->delete();

        foreach ([['orb174-first', '18081'], ['orb174-second', '18082']] as [$name, $port]) {
            FirewallRule::create([
                'node_id' => $node->id,
                'name' => $name,
                'action' => 'allow',
                'source' => 'any',
                'protocol' => 'tcp',
                'port' => $port,
                'status' => LifecycleStatus::Active,
            ]);
        }

        writeJson(fixtureState($node, $fixtureNames));
        break;

    case 'add-lifecycle':
        FirewallRule::query()
            ->where('node_id', $node->id)
            ->where('name', 'orb174-lifecycle')
            ->delete();
        FirewallRule::create([
            'node_id' => $node->id,
            'name' => 'orb174-lifecycle',
            'action' => 'allow',
            'source' => 'any',
            'protocol' => 'tcp',
            'port' => '18083',
            'status' => LifecycleStatus::Provisioning,
        ]);
        writeJson(fixtureState($node, $fixtureNames));
        break;

    case 'remove-lifecycle':
        FirewallRule::query()
            ->where('node_id', $node->id)
            ->where('name', 'orb174-lifecycle')
            ->delete();
        writeJson(fixtureState($node, $fixtureNames));
        break;

    case 'state':
        writeJson(fixtureState($node, $fixtureNames));
        break;

    case 'cleanup':
        FirewallRule::query()
            ->where('node_id', $node->id)
            ->whereIn('name', $fixtureNames)
            ->delete();
        writeJson(fixtureState($node, $fixtureNames));
        break;

    default:
        exit(64);
}
