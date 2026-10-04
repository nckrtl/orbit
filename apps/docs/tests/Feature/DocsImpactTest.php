<?php

declare(strict_types=1);

use App\Documentation\DocsImpact;

$GLOBALS['docsImpactFixtureRoots'] = [];

function removeDocsImpactFixturePath(string $path): void
{
    if (is_link($path) || is_file($path)) {
        if (! @unlink($path) && file_exists($path)) {
            throw new RuntimeException("Could not remove docs impact fixture file at {$path}.");
        }

        return;
    }
    if (! is_dir($path)) {
        return;
    }

    $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
    foreach ($iterator as $item) {
        removeDocsImpactFixturePath($item->getPathname());
    }
    unset($iterator);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        if (@rmdir($path) || ! is_dir($path)) {
            return;
        }
        usleep(10_000);
    }

    throw new RuntimeException("Could not remove docs impact fixture directory at {$path}.");
}

afterEach(function (): void {
    foreach ($GLOBALS['docsImpactFixtureRoots'] as $root) {
        removeDocsImpactFixturePath($root);
    }
    $GLOBALS['docsImpactFixtureRoots'] = [];
});

function docsImpactFixture(): string
{
    $root = sys_get_temp_dir().'/docs-impact-'.bin2hex(random_bytes(6));
    $GLOBALS['docsImpactFixtureRoots'][] = $root;
    mkdir($root.'/docs/reference', 0777, true);
    mkdir($root.'/docs/cli', 0777, true);
    mkdir($root.'/apps/gateway/routes', 0777, true);
    mkdir($root.'/apps/gateway/app/Domain/Tasks', 0777, true);
    mkdir($root.'/apps/cli/app/Commands', 0777, true);
    mkdir($root.'/apps/cli/app/Support', 0777, true);
    mkdir($root.'/apps/cli/config', 0777, true);
    mkdir($root.'/apps/gateway/app/Domain/Doctor', 0777, true);
    mkdir($root.'/apps/gateway/app/Models', 0777, true);
    mkdir($root.'/apps/gateway/database/migrations', 0777, true);
    mkdir($root.'/apps/gateway/app/Http/Mcp', 0777, true);
    mkdir($root.'/apps/gateway/app/Http/Controllers/Api', 0777, true);
    mkdir($root.'/apps/gateway/app/Http/Requests/Widgets', 0777, true);
    mkdir($root.'/apps/gateway/app/Console/Commands', 0777, true);
    mkdir($root.'/apps/cli/app/Commands/Routes', 0777, true);
    mkdir($root.'/apps/gateway/resources/mcp', 0777, true);
    mkdir($root.'/config', 0777, true);
    mkdir($root.'/apps/docs/config', 0777, true);
    file_put_contents($root.'/docs/reference/tasks.md', "---\ntitle: Tasks\ncovers:\n  - \"apps/gateway/app/Domain/Tasks/**\"\n---\nTasks\n");
    file_put_contents($root.'/docs/reference/widgets.md', "---\ntitle: Widgets\ncovers:\n  - \"apps/gateway/app/Models/**\"\n---\nWidgets\n");
    file_put_contents($root.'/docs/reference/environment-variables.md', "---\ntitle: Environment\n---\nEnvironment\n");
    file_put_contents($root.'/docs/reference/mcp.mdx', "---\ntitle: MCP\n---\nMCP\n");
    file_put_contents($root.'/docs/cli/node.mdx', "---\ntitle: Node\n---\nNode\n");
    file_put_contents($root.'/docs/cli/doctor.mdx', "---\ntitle: Doctor\n---\nDoctor\n");
    file_put_contents($root.'/docs/cli/env.mdx', "---\ntitle: Environment command\n---\nEnvironment command\n");
    file_put_contents($root.'/docs/cli/instance.mdx', "---\ntitle: Instance\n---\nInstance\n");
    file_put_contents($root.'/apps/gateway/routes/api.php', "<?php Route::get('/api/v1/widgets', [WidgetController::class, 'index']); Route::put('/api/v1/widgets', [WidgetController::class, 'update']);\n");
    file_put_contents($root.'/apps/gateway/app/Http/Controllers/Api/WidgetController.php', "<?php class WidgetController { public function update(UpdateWidgetRequest \$request) {} }\n");
    file_put_contents($root.'/apps/gateway/app/Http/Requests/Widgets/UpdateWidgetRequest.php', "<?php class UpdateWidgetRequest {}\n");
    file_put_contents($root.'/docs/openapi.json', json_encode(['paths' => ['/api/v1/widgets' => ['get' => ['responses' => ['default' => ['description' => 'widget.failed']]], 'put' => ['responses' => ['default' => ['description' => 'updated']]]]]], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/docs/docs.json', json_encode(['navigation' => ['tabs' => [['groups' => [['openapi' => '/openapi.json', 'pages' => ['GET /api/v1/widgets', 'PUT /api/v1/widgets']]]]]]], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/apps/gateway/resources/mcp/tools.json', json_encode(['tools' => [['name' => 'widget-list', 'method' => 'GET', 'path' => '/api/v1/widgets']]], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/.env.example', "# COMMENTED_KEY=ignored\nOLD_KEY=old\n");
    file_put_contents($root.'/apps/gateway/app/Domain/Tasks/RunTask.php', "<?php\n");
    file_put_contents($root.'/apps/gateway/app/Models/Widget.php', "<?php class Widget extends Model {}\n");
    file_put_contents($root.'/apps/cli/app/Commands/NodeList.php', "<?php class NodeList { protected \$signature = 'node:list {--new}'; }\n");
    file_put_contents($root.'/config/app.php', "<?php return [];\n");
    file_put_contents($root.'/apps/docs/config/docs-covers-ratchet.php', "<?php return ['docs/reference/tasks.md'];\n");
    exec('git -C '.escapeshellarg($root).' init -q');
    // Keep background Git processes from writing pack files during cleanup.
    exec('git -C '.escapeshellarg($root).' config gc.auto 0');
    exec('git -C '.escapeshellarg($root).' config maintenance.auto false');
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm fixture');

    return $root;
}

it('docs impact fixtures disable automatic Git maintenance before cleanup', function (): void {
    $root = docsImpactFixture();
    $settings = [];
    exec('git -C '.escapeshellarg($root).' config --local --get-regexp '.escapeshellarg('^(gc|maintenance)\.auto$'), $settings, $status);

    expect($status)->toBe(0)
        ->and($settings)->toContain('gc.auto 0', 'maintenance.auto false');

    removeDocsImpactFixturePath($root);

    expect(is_dir($root))->toBeFalse();
});

it('docs impact reports a new API operation and generator surfaces', function (): void {
    $root = docsImpactFixture();
    $report = new DocsImpact($root)->report('HEAD', ['apps/gateway/routes/api.php']);

    expect($report['verdict'])->toBe('docs_required')
        ->and(collect($report['impacted_pages'])->pluck('page'))->toContain('GET /api/v1/widgets', 'docs/reference/mcp.mdx')
        ->and(collect($report['surfaces_handled_by_generator'])->pluck('generator'))
        ->toContain('bin/docs-openapi')
        ->and($report['surfaces_handled_by_generator'][0]['status'])->toBe('missing');
});

it('docs impact maps a changed gateway request through its controller to the exact operation', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/gateway/app/Http/Requests/Widgets/UpdateWidgetRequest.php';
    $report = new DocsImpact($root)->report(null, [$path]);

    expect(collect($report['impacted_pages'])->pluck('page'))->toContain('PUT /api/v1/widgets')
        ->and(collect($report['impacted_pages'])->pluck('page'))->not->toContain('GET /api/v1/widgets')
        ->and($report['errors'])->toBe([]);
});

it('docs impact reports a new environment key to its reference page', function (): void {
    $root = docsImpactFixture();
    file_put_contents($root.'/.env.example', "# COMMENTED_KEY=ignored\nNEW_KEY=value\n");
    $report = new DocsImpact($root)->report(null, ['.env.example']);

    expect($report['verdict'])->toBe('docs_required')
        ->and(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/reference/environment-variables.md')
        ->and(collect($report['surfaces'])->pluck('reason')->implode("\n"))->toContain('NEW_KEY')
        ->and(collect($report['surfaces'])->pluck('reason')->implode("\n"))->not->toContain('COMMENTED_KEY');
});

it('docs impact reports environment keys removed from a retained file against the base', function (): void {
    $root = docsImpactFixture();
    $base = trim(shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD'));
    file_put_contents($root.'/.env.example', "# OLD_KEY=commented\nNEW_KEY=value\n");
    $report = new DocsImpact($root)->report($base, ['.env.example']);

    expect(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/reference/environment-variables.md')
        ->and(collect($report['surfaces'])->pluck('reason')->implode(' '))->toContain('Removed from current source: OLD_KEY');
});

it('docs impact reports the last env call removed from a retained config file', function (): void {
    $root = docsImpactFixture();
    mkdir($root.'/apps/gateway/config', 0777, true);
    $path = 'apps/gateway/config/doctor.php';
    file_put_contents($root.'/'.$path, "<?php return env('GATEWAY_DOCTOR_KEY');\n");
    exec('git -C '.escapeshellarg($root).' add '.escapeshellarg($path));
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm env-call');
    $base = trim(shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD'));
    file_put_contents($root.'/'.$path, "<?php return null;\n");
    $report = new DocsImpact($root)->report($base, [$path]);

    expect(collect($report['surfaces'])->pluck('reason')->implode(' '))->toContain('Removed from current source: GATEWAY_DOCTOR_KEY');
});

it('docs impact reports removed error identifiers from a retained source file', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/gateway/app/Domain/Tasks/TaskGroupGuard.php';
    file_put_contents($root.'/'.$path, "<?php renderGatewayFailure(errorCode: 'tasks.group_invalid');\n");
    exec('git -C '.escapeshellarg($root).' add '.escapeshellarg($path));
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm task-error');
    $base = trim(shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD'));
    file_put_contents($root.'/'.$path, "<?php final class TaskGroupGuard {}\n");
    $report = new DocsImpact($root)->report($base, [$path]);

    expect(collect($report['surfaces'])->pluck('reason')->implode(' '))->toContain('Removed from current source: error identifier tasks.group_invalid');
});

it('docs impact reports removed Doctor codes from a retained enum file', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/gateway/app/Domain/Doctor/WidgetDoctorIssueCode.php';
    $old = "<?php enum WidgetDoctorIssueCode implements DoctorIssueCode { case Old = 'widget.old_issue'; case New = 'widget.new_issue'; }\n";
    file_put_contents($root.'/'.$path, $old);
    exec('git -C '.escapeshellarg($root).' add '.escapeshellarg($path));
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm doctor-codes');
    $base = trim(shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD'));
    file_put_contents($root.'/'.$path, "<?php enum WidgetDoctorIssueCode implements DoctorIssueCode { case New = 'widget.new_issue'; }\n");
    $report = new DocsImpact($root)->report($base, [$path]);

    expect(collect($report['surfaces'])->pluck('reason')->implode(' '))->toContain('Removed from current source: Doctor unknown issue code widget.old_issue');
});

it('docs impact reports a registered command removed from a retained file', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/gateway/app/Console/Kernel.php';
    file_put_contents($root.'/'.$path, "<?php Schedule::command('tasks:tick')->everyMinute();\n");
    exec('git -C '.escapeshellarg($root).' add '.escapeshellarg($path));
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm command-registration');
    $base = trim(shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD'));
    file_put_contents($root.'/'.$path, "<?php // the registration was removed\n");
    $report = new DocsImpact($root)->report($base, [$path]);

    expect(collect($report['surfaces'])->pluck('reason')->implode(' '))->toContain('Removed from current source: registered command tasks:tick');
});

it('docs impact extracts node error literals from CLI support code', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/cli/app/Support/NodeSettingOptions.php';
    file_put_contents($root.'/'.$path, "<?php return ['code' => 'node.setting_unknown'];\n");
    $report = new DocsImpact($root)->report(null, [$path]);

    expect(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/cli/node.mdx')
        ->and(collect($report['surfaces'])->pluck('reason')->implode(' '))->toContain('node.setting_unknown');
});

it('docs impact extracts required error codes passed to gateway failure renderers', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/cli/app/Support/NodeFailure.php';
    file_put_contents($root.'/'.$path, "<?php renderGatewayFailure(requiredCode: 'node.setting_required');\n");
    $report = new DocsImpact($root)->report(null, [$path]);

    expect(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/cli/node.mdx')
        ->and(collect($report['surfaces'])->pluck('reason')->implode(' '))->toContain('node.setting_required');
});

it('docs impact extracts literal error-code constants from CLI exception classes', function (): void {
    $root = docsImpactFixture();
    file_put_contents($root.'/docs/cli/gateway.mdx', "---\ntitle: Gateway\n---\nGateway\n");
    mkdir($root.'/apps/cli/app/Exceptions', 0777, true);
    $path = 'apps/cli/app/Exceptions/GatewayConfigException.php';
    file_put_contents($root.'/'.$path, "<?php final class GatewayConfigException { public const string CONFIG_NOT_PRIVATE = 'gateway.config_not_private'; }\n");
    $report = new DocsImpact($root)->report(null, [$path]);

    expect(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/cli/gateway.mdx')
        ->and(collect($report['surfaces'])->pluck('reason')->implode(' '))->toContain('gateway.config_not_private')
        ->and($report['errors'])->toBe([]);
});

it('docs impact reports a new CLI option to its family page', function (): void {
    $root = docsImpactFixture();
    $report = new DocsImpact($root)->report(null, ['apps/cli/app/Commands/NodeList.php']);

    expect($report['verdict'])->toBe('docs_required')
        ->and(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/cli/node.mdx');
});

it('docs impact reports changes selected by a covers glob', function (): void {
    $root = docsImpactFixture();
    $report = new DocsImpact($root)->report(null, ['apps/gateway/app/Domain/Tasks/RunTask.php']);

    expect($report['verdict'])->toBe('docs_required')
        ->and($report['impacted_pages'][0]['page'])->toBe('docs/reference/tasks.md')
        ->and($report['impacted_pages'][0]['reasons'][0])->toContain('covers:');
});

it('docs impact ignores a covered page changed only in documentation', function (): void {
    $root = docsImpactFixture();
    $report = new DocsImpact($root)->report(null, ['docs/reference/tasks.md']);

    expect($report['verdict'])->toBe('no_docs_change')->and($report['impacted_pages'])->toBe([]);
});

it('docs impact maps Doctor issue enum cases to the Doctor page', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/gateway/app/Domain/Doctor/WidgetDoctorIssueCode.php';
    file_put_contents($root.'/'.$path, "<?php enum WidgetDoctorIssueCode implements DoctorIssueCode { case Missing = 'widget.missing'; }\n");
    $report = new DocsImpact($root)->report(null, [$path]);

    expect($report['verdict'])->toBe('docs_required')
        ->and(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/cli/doctor.mdx')
        ->and(collect($report['surfaces'])->pluck('reason')->implode(' '))->toContain('widget.missing');
});

it('docs impact assigns API error codes to operation response owners', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/gateway/app/Http/Controllers/Api/WidgetController.php';
    file_put_contents($root.'/'.$path, "<?php errorCode: 'widget.failed';\n");
    $report = new DocsImpact($root)->report(null, [$path]);

    expect(collect($report['impacted_pages'])->pluck('page'))->toContain('GET /api/v1/widgets');
});

it('docs impact maps Data and SDK response classes through generated component names and dependencies', function (): void {
    $root = docsImpactFixture();
    $paths = ['/api/v1/nodes' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/NodeEnvelope']]]]]]]];
    $components = [
        'NodeEnvelope' => ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/Node']]],
        'Node' => ['type' => 'object', 'properties' => ['settings' => ['$ref' => '#/components/schemas/NodeSettings']]],
        'NodeSettings' => ['type' => 'object', 'properties' => ['user' => ['type' => 'string']]],
    ];
    file_put_contents($root.'/docs/openapi.json', json_encode(['paths' => $paths, 'components' => ['schemas' => $components]], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/docs/docs.json', json_encode(['navigation' => ['tabs' => [['groups' => [['openapi' => '/openapi.json', 'pages' => ['GET /api/v1/nodes']]]]]]], JSON_THROW_ON_ERROR));
    mkdir($root.'/apps/gateway/app/Data/Nodes', 0777, true);
    mkdir($root.'/packages/php-sdk/src/Responses/Nodes', 0777, true);
    $sources = [
        'apps/gateway/app/Data/Nodes/NodeData.php',
        'apps/gateway/app/Data/Nodes/NodeSettingsData.php',
        'packages/php-sdk/src/Responses/Nodes/NodeResponse.php',
    ];
    foreach ($sources as $sourcePath) {
        file_put_contents($root.'/'.$sourcePath, "<?php // generated schema source\n");
        $report = new DocsImpact($root)->report(null, [$sourcePath]);
        expect(collect($report['impacted_pages'])->pluck('page'))->toContain('GET /api/v1/nodes');
    }
});

it('docs impact refuses unowned migration changes', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/gateway/database/migrations/2026_01_01_create_widgets.php';
    file_put_contents($root.'/'.$path, "<?php Schema::create('orphaned', function (Blueprint \$table): void { \$table->string('name'); });\n");
    $report = new DocsImpact($root)->report(null, [$path]);

    expect($report['verdict'])->toBe('docs_required')
        ->and($report['errors'])->toContain("Unowned migration surface in {$path}; add a matching covers glob.");
});

it('docs impact extracts chained Blueprint changes, renames, and index operations', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/gateway/database/migrations/2026_01_02_update_nodes.php';
    file_put_contents($root.'/'.$path, <<<'PHP'
<?php
Schema::table('nodes', function (Blueprint $table): void {
    $table->string('user')->nullable(false)->change();
    $table->renameColumn('ssh_user', 'user');
    $table->index(['user'], 'nodes_user_index');
});
PHP);
    $report = new DocsImpact($root)->report(null, [$path]);
    $reasons = collect($report['surfaces'])->pluck('reason')->implode(' ');

    expect($reasons)->toContain('nodes.string(user)', 'nodes.nullable(false)', 'nodes.change()', 'nodes.renameColumn(ssh_user,user)', 'nodes.index(user,nodes_user_index)');
});

it('docs impact uses a model coverage glob for the matching migration table', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/gateway/database/migrations/2026_01_01_create_widgets.php';
    file_put_contents($root.'/'.$path, "<?php Schema::create('widgets', function (Blueprint \$table): void { \$table->string('name'); });\n");
    $report = new DocsImpact($root)->report(null, [$path]);

    expect($report['verdict'])->toBe('docs_required')
        ->and(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/reference/widgets.md')
        ->and(collect($report['surfaces'])->pluck('reason')->implode(' '))->toContain('model resource apps/gateway/app/Models/Widget.php');
});

it('docs impact maps scheduled commands and schedule timing', function (): void {
    $root = docsImpactFixture();
    file_put_contents($root.'/docs/reference/schedules.md', "---\ntitle: Schedules\n---\nSchedules\n");
    $path = 'apps/gateway/app/Console/Kernel.php';
    file_put_contents($root.'/'.$path, "<?php Schedule::command('tasks:tick')->everyMinute();\n");
    $report = new DocsImpact($root)->report(null, [$path]);

    expect(collect($report['impacted_pages'])->pluck('page'))
        ->toContain('docs/reference/tasks.md', 'docs/reference/schedules.md');
});

it('docs impact owns the scheduled development-default deployment command and its cadence', function (): void {
    $root = docsImpactFixture();
    file_put_contents($root.'/docs/reference/schedules.md', "---\ntitle: Schedules\n---\nSchedules\n");
    file_put_contents($root.'/docs/reference/deployments.md', "---\ntitle: Instance releases\n---\nDevelopment defaults\n");
    $path = 'apps/gateway/app/Console/Kernel.php';
    file_put_contents($root.'/'.$path, "<?php Schedule::command('orbit:deploy-development-defaults')->everyMinute()->withoutOverlapping(90);\n");
    $report = new DocsImpact($root)->report(null, [$path]);

    expect($report['errors'])->toBe([])
        ->and(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/reference/deployments.md', 'docs/reference/schedules.md')
        ->and(collect($report['surfaces'])->pluck('reason')->implode(' '))->toContain('orbit:deploy-development-defaults', 'everyMinute', 'withoutOverlapping');
});

it('docs impact owns reserved probe recovery commands and their scheduler cadence', function (): void {
    $root = docsImpactFixture();
    file_put_contents($root.'/docs/reference/schedules.md', "---\ntitle: Schedules\n---\nSchedules\n");
    file_put_contents($root.'/docs/reference/project-documents.md', "---\ntitle: Project Documents\n---\nProbe recovery\n");
    $schedule = 'apps/gateway/app/Console/Kernel.php';
    $repair = 'apps/gateway/app/Console/Commands/RepairDocumentProbeCredentials.php';
    file_put_contents($root.'/'.$schedule, "<?php Schedule::command('project-documents:probes:reconcile')->everyMinute()->withoutOverlapping(10);\n");
    file_put_contents($root.'/'.$repair, "<?php class RepairDocumentProbeCredentials { protected \$signature = 'project-documents:probes:repair {record}'; }\n");

    $report = new DocsImpact($root)->report(null, [$schedule, $repair]);

    expect($report['errors'])->toBe([])
        ->and(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/reference/project-documents.md', 'docs/reference/schedules.md')
        ->and(collect($report['surfaces'])->pluck('reason')->implode(' '))->toContain('project-documents:probes:reconcile', 'project-documents:probes:repair', 'everyMinute', 'withoutOverlapping');
});

it('docs impact reports MCP tool ownership and generator status', function (): void {
    $root = docsImpactFixture();
    file_put_contents($root.'/docs/reference/mcp.mdx', "---\ntitle: MCP\n---\nMCP\n");
    $path = 'apps/gateway/app/Http/Mcp/ToolDefinition.php';
    file_put_contents($root.'/'.$path, "<?php return ['name' => 'orbit_widget'];\n");
    $report = new DocsImpact($root)->report(null, [$path]);

    expect($report['verdict'])->toBe('docs_required')
        ->and(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/reference/mcp.mdx')
        ->and(collect($report['surfaces_handled_by_generator'])->pluck('generator'))->toContain('bin/mcp-tools');
});

it('docs impact rejects unknown CLI families instead of guessing an owner', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/cli/app/Commands/UnknownCommand.php';
    file_put_contents($root.'/'.$path, "<?php class UnknownCommand { protected \$signature = 'unknown:run'; }\n");
    $report = new DocsImpact($root)->report(null, [$path]);

    expect($report['verdict'])->toBe('docs_required')
        ->and($report['errors'])->toContain("Unknown CLI command family [unknown] in {$path}.");
});

it('docs impact detects CLI command registration changes', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/cli/config/commands.php';
    file_put_contents($root.'/'.$path, "<?php return ['paths' => [app_path('Commands')]];\n");
    $report = new DocsImpact($root)->report(null, [$path]);

    expect(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/cli/node.mdx')
        ->and(collect($report['surfaces'])->pluck('kind'))->toContain('cli_registration');
});

it('docs impact maps Instance environment CLI commands to the env page', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/cli/app/Commands/Instances/UpdateInstanceEnvironmentCommand.php';
    mkdir(dirname($root.'/'.$path), 0777, true);
    file_put_contents($root.'/'.$path, "<?php class UpdateInstanceEnvironmentCommand { protected \$signature = 'instance:env-update'; UpdateAppInstanceEnvironmentRequest::class; }\n");
    $report = new DocsImpact($root)->report(null, [$path]);

    expect(collect($report['impacted_pages'])->pluck('page'))
        ->toContain('docs/cli/instance.mdx', 'docs/cli/env.mdx');
});

it('docs impact expands planned directories and reports covered domain paths', function (): void {
    $root = docsImpactFixture();
    $report = new DocsImpact($root)->report(null, ['apps/gateway/app/Domain/Tasks']);

    expect(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/reference/tasks.md');
});

it('docs impact resolves planned Doctor files and rejects unknown planned config files', function (): void {
    $root = docsImpactFixture();
    $report = new DocsImpact($root)->report(null, [
        'apps/gateway/app/Domain/Doctor/NewDoctorIssueCode.php',
        'apps/gateway/config/runtime.php',
    ]);

    expect(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/cli/doctor.mdx')
        ->and($report['errors'])->toContain('Unable to resolve planned surface [apps/gateway/config/runtime.php]; provide an existing path or a covers: owner.');
});

it('docs impact extracts a deleted surface from the base commit', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/gateway/app/Domain/Tasks/TaskGroupGuard.php';
    file_put_contents($root.'/'.$path, "<?php renderGatewayFailure(requiredCode: 'tasks.group_invalid');\n");
    exec('git -C '.escapeshellarg($root).' add '.escapeshellarg($path));
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm guard');
    $base = trim(shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD'));
    unlink($root.'/'.$path);
    $report = new DocsImpact($root)->report($base, [$path]);

    expect(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/reference/tasks.md')
        ->and($report['errors'])->toBe([])
        ->and(collect($report['surfaces'])->pluck('owner'))->toContain('docs/reference/tasks.md');
});

it('docs impact maps grouped middleware routes to exact prefixed operations and actions', function (): void {
    $root = docsImpactFixture();
    file_put_contents($root.'/apps/gateway/routes/api.php', <<<'PHP'
<?php
Route::prefix('v1')->group(function (): void {
    Route::middleware([Auth::class])->get('realtime', [RealtimeConfigController::class, 'show']);
    Route::middleware([Auth::class])->prefix('instances/{instance}/annotations')->group(function (): void {
        Route::get('', [AnnotationsController::class, 'index']);
        Route::post('', [AnnotationsController::class, 'store']);
    });
    Route::middleware([Auth::class])->get('nodes', [NodesController::class, 'index']);
});
PHP);
    $paths = [
        '/api/v1/realtime' => ['get' => []],
        '/api/v1/instances/{instance}/annotations' => ['get' => [], 'post' => []],
        '/api/v1/nodes' => ['get' => []],
        '/api/v1/nodes/{node}/access/{consumer_node}' => ['put' => []],
    ];
    file_put_contents($root.'/docs/openapi.json', json_encode(['paths' => $paths], JSON_THROW_ON_ERROR));
    $navigationKeys = [];
    foreach ($paths as $uri => $operations) {
        foreach (array_keys($operations) as $method) {
            $navigationKeys[] = strtoupper($method).' '.$uri;
        }
    }
    file_put_contents($root.'/docs/docs.json', json_encode(['navigation' => ['tabs' => [['groups' => [['openapi' => '/openapi.json', 'pages' => $navigationKeys]]]]]], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/apps/gateway/app/Http/Controllers/Api/RealtimeConfigController.php', '<?php class RealtimeConfigController { public function show() {} }\\n');
    file_put_contents($root.'/apps/gateway/app/Http/Controllers/Api/AnnotationsController.php', '<?php class AnnotationsController { public function index() {} public function store() {} }\\n');
    file_put_contents($root.'/apps/gateway/app/Http/Controllers/Api/NodesController.php', '<?php class NodesController { public function index() {} }\\n');

    $realtime = new DocsImpact($root)->report(null, ['apps/gateway/app/Http/Controllers/Api/RealtimeConfigController.php']);
    $annotations = new DocsImpact($root)->report(null, ['apps/gateway/app/Http/Controllers/Api/AnnotationsController.php']);
    $nodes = new DocsImpact($root)->report(null, ['apps/gateway/app/Http/Controllers/Api/NodesController.php']);

    expect(collect($realtime['impacted_pages'])->pluck('page'))->toContain('GET /api/v1/realtime')
        ->and(collect($annotations['impacted_pages'])->pluck('page'))->toContain('GET /api/v1/instances/{instance}/annotations', 'POST /api/v1/instances/{instance}/annotations')
        ->and(collect($nodes['impacted_pages'])->pluck('page'))->toContain('GET /api/v1/nodes')
        ->and(collect($nodes['impacted_pages'])->pluck('page'))->not->toContain('PUT /api/v1/nodes/{node}/access/{consumer_node}');
});

it('docs impact parses the first CLI command token before its option signature', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/cli/app/Commands/Doctor/DoctorCommand.php';
    mkdir(dirname($root.'/'.$path), 0777, true);
    file_put_contents($root.'/'.$path, <<<'PHP'
<?php class DoctorCommand { protected $signature = 'doctor {--node=}'; }
PHP);
    exec('git -C '.escapeshellarg($root).' add '.escapeshellarg($path));
    $registration = 'apps/cli/config/commands.php';
    file_put_contents($root.'/'.$registration, "<?php return ['paths' => [app_path('Commands')]];\n");
    $report = new DocsImpact($root)->report(null, [$path, $registration]);

    expect(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/cli/doctor.mdx')
        ->and(collect($report['surfaces'])->pluck('kind'))->toContain('cli_signature', 'cli_registration')
        ->and($report['errors'])->toBe([]);
});

it('docs impact rejects absolute covers patterns before normalization', function (): void {
    $root = docsImpactFixture();
    file_put_contents($root.'/docs/reference/tasks.md', "---\ntitle: Tasks\ncovers:\n  - /bin/review-check\n---\nTasks\n");

    expect(new DocsImpact($root, [])->coverageFindings())->toContain([
        'page' => 'docs/reference/tasks.md',
        'pattern' => '/bin/review-check',
        'message' => 'covers: must be a safe repository-relative glob.',
    ]);
});

it('docs impact assigns a covered TaskGroupGuard error to its covering page', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/gateway/app/Domain/Tasks/TaskGroupGuard.php';
    file_put_contents($root.'/'.$path, "<?php renderGatewayFailure(requiredCode: 'tasks.group_invalid');\n");
    $report = new DocsImpact($root)->report(null, [$path]);

    expect(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/reference/tasks.md')
        ->and($report['errors'])->toBe([])
        ->and(collect($report['surfaces'])->where('kind', 'error_code')->pluck('owner'))->toContain('docs/reference/tasks.md');
});

it('docs impact compares MCP manifest tool descriptions with the base', function (): void {
    $root = docsImpactFixture();
    $manifest = json_decode(file_get_contents($root.'/apps/gateway/resources/mcp/tools.json'), true);
    $manifest['tools'][0]['description'] = 'A changed widget listing contract.';
    $manifest['tools'][] = ['name' => 'widget-create', 'title' => 'Create a widget', 'description' => 'Create a widget through the API.'];
    file_put_contents($root.'/apps/gateway/resources/mcp/tools.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $report = new DocsImpact($root)->report('HEAD', ['apps/gateway/resources/mcp/tools.json']);

    expect(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/reference/mcp.mdx')
        ->and(collect($report['surfaces'])->where('kind', 'mcp_tool')->pluck('reason')->implode(' '))->toContain('widget-list name or description changed', 'widget-create added');
});

it('docs impact keeps coverage growth anchored to the branch baseline and listed pages present', function (): void {
    $root = docsImpactFixture();
    file_put_contents($root.'/apps/docs/config/docs-covers-ratchet.php', "<?php return ['docs/reference/tasks.md', 'docs/reference/widgets.md'];\n");
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm ratchet-growth');
    $baseline = trim(shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD'));
    exec('git -C '.escapeshellarg($root).' update-ref refs/remotes/origin/main '.escapeshellarg($baseline));
    file_put_contents($root.'/apps/docs/config/docs-covers-ratchet.php', "<?php return ['docs/reference/tasks.md'];\n");
    exec('git -C '.escapeshellarg($root).' add apps/docs/config/docs-covers-ratchet.php');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm ratchet-shrink');
    file_put_contents($root.'/apps/docs/config/docs-covers-ratchet.php', "<?php return ['docs/reference/tasks.md', 'docs/reference/missing.md'];\n");

    expect(new DocsImpact($root)->coverageFindings())->toContain([
        'page' => 'apps/docs/config/docs-covers-ratchet.php',
        'pattern' => 'docs/reference/widgets.md',
        'message' => 'The coverage ratchet cannot remove docs/reference/widgets.md.',
    ])->and(new DocsImpact($root)->coverageFindings())->toContain([
        'page' => 'apps/docs/config/docs-covers-ratchet.php',
        'pattern' => 'docs/reference/missing.md',
        'message' => 'The coverage ratchet lists missing page docs/reference/missing.md.',
    ]);
});

it('docs impact docs-lint accepts pages without covers', function (): void {
    $root = docsImpactFixture();

    expect(new DocsImpact($root, [])->coverageFindings())->toBe([]);
});

it('docs impact docs-lint accepts brace alternatives in coverage globs', function (): void {
    $root = docsImpactFixture();
    file_put_contents($root.'/docs/reference/tasks.md', "---\ntitle: Tasks\ncovers:\n  - \"apps/gateway/app/{Domain/Tasks,Models}/**\"\n---\nTasks\n");

    expect(new DocsImpact($root, [])->coverageFindings())->toBe([]);
});

it('docs impact docs-lint rejects covers globs with no tracked match', function (): void {
    $root = docsImpactFixture();
    file_put_contents($root.'/docs/reference/tasks.md', "---\ntitle: Tasks\ncovers:\n  - \"does/not/exist/**\"\n---\nTasks\n");

    expect(new DocsImpact($root, [])->coverageFindings())->toContain([
        'page' => 'docs/reference/tasks.md',
        'pattern' => 'does/not/exist/**',
        'message' => 'covers: pattern does not match a tracked path.',
    ]);
});

it('docs impact docs-lint rejects shortening the committed coverage ratchet', function (): void {
    $root = docsImpactFixture();
    file_put_contents($root.'/apps/docs/config/docs-covers-ratchet.php', "<?php return [];\n");

    expect(new DocsImpact($root)->coverageFindings())->toContain([
        'page' => 'apps/docs/config/docs-covers-ratchet.php',
        'pattern' => 'docs/reference/tasks.md',
        'message' => 'The coverage ratchet cannot remove docs/reference/tasks.md.',
    ]);
});

it('docs impact docs-lint enforces the coverage ratchet', function (): void {
    $root = docsImpactFixture();
    file_put_contents($root.'/docs/reference/tasks.md', "---\ntitle: Tasks\n---\nTasks\n");

    expect(new DocsImpact($root, ['docs/reference/tasks.md'])->coverageFindings())->toContain([
        'page' => 'docs/reference/tasks.md',
        'pattern' => 'covers:',
        'message' => 'covers: cannot be removed from the committed coverage ratchet.',
    ]);
});

it('docs impact suppresses generated API pages when all generators are current', function (): void {
    $root = docsImpactFixture();
    mkdir($root.'/bin', 0777, true);
    foreach (['docs-openapi', 'api-fixtures', 'mcp-tools'] as $generator) {
        file_put_contents($root.'/bin/'.$generator, "#!/bin/sh\nexit 0\n");
        chmod($root.'/bin/'.$generator, 0755);
    }
    $report = new DocsImpact($root)->report(null, ['apps/gateway/routes/api.php']);

    expect($report['verdict'])->toBe('no_docs_change')
        ->and($report['impacted_pages'])->toBe([])
        ->and(collect($report['surfaces_handled_by_generator'])->pluck('current')->unique()->all())->toBe([true]);
});

it('docs impact treats a current generated artifact as handled without a manual page', function (): void {
    $root = docsImpactFixture();
    mkdir($root.'/bin', 0777, true);
    file_put_contents($root.'/bin/docs-openapi', "#!/bin/sh\nexit 0\n");
    chmod($root.'/bin/docs-openapi', 0755);
    $report = new DocsImpact($root)->report(null, ['docs/openapi.json']);

    expect($report['verdict'])->toBe('no_docs_change')
        ->and($report['impacted_pages'])->toBe([])
        ->and($report['surfaces_handled_by_generator'][0]['current'])->toBeTrue();
});

it('docs impact exposes surfaces handled by generators', function (): void {
    $root = docsImpactFixture();
    $report = new DocsImpact($root)->report(null, ['apps/gateway/routes/api.php']);

    expect($report['surfaces_handled_by_generator'])->not->toBeEmpty()
        ->and($report['surfaces_handled_by_generator'][0])->toHaveKeys(['generator', 'paths', 'current']);
});

it('docs impact maps Gateway class command signatures to their owner page', function (): void {
    $root = docsImpactFixture();
    file_put_contents($root.'/docs/reference/node-agent.md', "---\ntitle: Node agent\n---\nNode agent\n");
    $path = 'apps/gateway/app/Console/Commands/AgentViewCommand.php';
    file_put_contents($root.'/'.$path, "<?php class AgentViewCommand { protected \$signature = 'orbit:agent-view'; }\n");
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm command');
    file_put_contents($root.'/'.$path, "<?php class AgentViewCommand { protected \$signature = 'orbit:agent-display'; }\n");

    $report = new DocsImpact($root)->report('HEAD', [$path]);

    expect(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/reference/node-agent.md')
        ->and($report['errors'])->toContain("Unowned Gateway console command [orbit:agent-display] in {$path}.");
});

it('docs impact recognizes the explicitly internal CLI family', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/cli/app/Commands/InternalDatabaseLocalCommand.php';
    file_put_contents($root.'/'.$path, "<?php class InternalDatabaseLocalCommand { protected \$signature = 'internal:database-local'; }\n");

    $report = new DocsImpact($root)->report(null, [$path]);

    expect($report['errors'])->toBe([])
        ->and(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/reference/tasks.md');
});

it('docs impact ignores comment-only CLI command edits and unchanged source errors', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/cli/app/Commands/NodeList.php';
    file_put_contents($root.'/'.$path, "<?php class NodeList { protected \$signature = 'node:list'; }\n");
    file_put_contents($root.'/apps/gateway/database/migrations/2026_01_01_create_orphans.php', "<?php Schema::create('orphans', function (Blueprint \$table): void {});\n");
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm baseline');
    file_put_contents($root.'/'.$path, "<?php // comment only\nclass NodeList { protected \$signature = 'node:list'; }\n");
    file_put_contents($root.'/apps/gateway/database/migrations/2026_01_01_create_orphans.php', "<?php // comment only\nSchema::create('orphans', function (Blueprint \$table): void {});\n");

    $report = new DocsImpact($root)->report('HEAD', [$path, 'apps/gateway/database/migrations/2026_01_01_create_orphans.php']);

    expect($report['errors'])->toBe([])
        ->and(collect($report['impacted_pages'])->pluck('page'))->not->toContain('docs/cli/node.mdx');
});

it('docs impact reports deleted and signature-removed CLI command owners', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/cli/app/Commands/NodeList.php';
    exec('git -C '.escapeshellarg($root).' rm -q '.escapeshellarg($path));
    $report = new DocsImpact($root)->report('HEAD', [$path]);

    expect(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/cli/node.mdx');

    file_put_contents($root.'/'.$path, "<?php class NodeList { protected \$signature = 'node:list'; }\n");
    exec('git -C '.escapeshellarg($root).' add '.escapeshellarg($path));
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm restored');
    file_put_contents($root.'/'.$path, "<?php class NodeList {}\n");
    $removedSignature = new DocsImpact($root)->report('HEAD', [$path]);

    expect(collect($removedSignature['impacted_pages'])->pluck('page'))->toContain('docs/cli/node.mdx');
});

it('docs impact marks both CLI owners when a command changes families', function (): void {
    $root = docsImpactFixture();
    file_put_contents($root.'/docs/cli/route.mdx', "---\ntitle: Route\n---\nRoute\n");
    $path = 'apps/cli/app/Commands/NodeList.php';
    file_put_contents($root.'/'.$path, "<?php class NodeList { protected \$signature = 'node:list'; }\n");
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm baseline');
    file_put_contents($root.'/'.$path, "<?php class NodeList { protected \$signature = 'route:list'; }\n");

    $report = new DocsImpact($root)->report('HEAD', [$path]);

    expect(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/cli/node.mdx', 'docs/cli/route.mdx');
});

it('docs impact ignores comment-only changes to actual CLI commands with error identifiers', function (): void {
    $root = docsImpactFixture();
    mkdir($root.'/bin', 0777, true);
    foreach (['bin/cli-contract', 'bin/docs-openapi'] as $generator) {
        file_put_contents($root.'/'.$generator, "#!/bin/sh\nexit 0\n");
        chmod($root.'/'.$generator, 0755);
    }
    $path = 'apps/cli/app/Commands/Routes/CreateRouteCommand.php';
    $actual = file_get_contents(base_path('../../apps/cli/app/Commands/Routes/CreateRouteCommand.php'));
    expect($actual)->toBeString();
    file_put_contents($root.'/'.$path, $actual);
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm baseline');
    file_put_contents($root.'/'.$path, "<?php // unrelated comment\n".$actual);

    $report = new DocsImpact($root)->report('HEAD', [$path]);

    expect($report['verdict'])->toBe('no_docs_change')
        ->and($report['impacted_pages'])->toBe([])
        ->and($report['errors'])->toBe([]);
});

it('docs impact ignores comment-only changes to the actual Gateway task command', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/gateway/app/Console/Commands/RenderTaskPromptCommand.php';
    $source = file_get_contents(dirname(__DIR__, 4).'/'.$path);
    expect($source)->toBeString();
    file_put_contents($root.'/'.$path, $source);
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm baseline');
    file_put_contents($root.'/'.$path, "<?php // unrelated comment\n".$source);

    $report = new DocsImpact($root)->report('HEAD', [$path]);

    expect($report['verdict'])->toBe('no_docs_change')
        ->and($report['impacted_pages'])->toBe([]);
});

it('docs impact ignores unchanged unowned error identifiers moved by a comment', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/gateway/app/Domain/Unmapped/UnownedIdentifier.php';
    mkdir(dirname($root.'/'.$path), 0777, true);
    $source = "<?php return ['errorCode' => 'example.failed'];\n";
    file_put_contents($root.'/'.$path, $source);
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm baseline');
    file_put_contents($root.'/'.$path, "<?php // comment shifts the diagnostic line\n".$source);

    $report = new DocsImpact($root)->report('HEAD', [$path]);

    expect($report['errors'])->toBe([]);
});

it('docs impact reports unowned error identifiers genuinely added and removed', function (): void {
    $root = docsImpactFixture();
    $path = 'apps/gateway/app/Domain/Unmapped/UnownedIdentifier.php';
    mkdir(dirname($root.'/'.$path), 0777, true);
    file_put_contents($root.'/'.$path, "<?php return ['errorCode' => 'example.old_failure'];\n");
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm baseline');
    file_put_contents($root.'/'.$path, "<?php return ['errorCode' => 'example.new_failure'];\n");

    $report = new DocsImpact($root)->report('HEAD', [$path]);

    expect($report['errors'])->toContain('Unowned error identifier [example.new_failure] in '.$path.':1.')
        ->and($report['errors'])->toContain('Removed from current source: Unowned error identifier [example.old_failure] in '.$path.':1.');
});

it('docs impact reports Laravel console route schedule changes', function (): void {
    $root = docsImpactFixture();
    file_put_contents($root.'/docs/reference/schedules.md', "---\ntitle: Schedules\n---\nSchedules\n");
    $path = 'apps/gateway/routes/console.php';
    file_put_contents($root.'/'.$path, "<?php Schedule::command('tasks:tick')->dailyAt('02:30');\n");
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm baseline');
    file_put_contents($root.'/'.$path, "<?php Schedule::command('tasks:tick')->dailyAt('02:45');\n");

    $report = new DocsImpact($root)->report('HEAD', [$path]);

    expect(collect($report['impacted_pages'])->pluck('page'))->toContain('docs/reference/tasks.md', 'docs/reference/schedules.md');
});

it('docs impact reports Gateway TaskSchedule registration changes only from registration sources', function (): void {
    $root = docsImpactFixture();
    file_put_contents($root.'/docs/reference/schedules.md', "---\ntitle: Schedules\n---\nSchedules\n");
    $path = 'apps/gateway/app/Domain/Tasks/TaskSchedule.php';
    $source = file_get_contents(dirname(__DIR__, 4).'/'.$path);
    expect($source)->toBeString();
    file_put_contents($root.'/'.$path, $source);
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm baseline');
    file_put_contents($root.'/'.$path, preg_replace('/->everyTenSeconds\(\)/', '->everyMinute()', $source, 1) ?? $source);
    $scheduleReport = new DocsImpact($root)->report('HEAD', [$path]);

    $unrelated = 'apps/gateway/app/Domain/Tasks/TaskScheduleComment.php';
    file_put_contents($root.'/'.$unrelated, "<?php // Schedule::command('tasks:tick')->everyMinute() is discussed here.\n");
    $unrelatedReport = new DocsImpact($root)->report(null, [$unrelated]);

    expect(collect($scheduleReport['impacted_pages'])->pluck('page'))->toContain('docs/reference/schedules.md')
        ->and(collect($unrelatedReport['impacted_pages'])->pluck('page'))->not->toContain('docs/reference/schedules.md');
});

it('docs impact treats API sources as generator-handled when all API generators pass', function (): void {
    $root = docsImpactFixture();
    mkdir($root.'/bin', 0777, true);
    foreach (['bin/docs-openapi', 'bin/api-fixtures', 'bin/mcp-tools'] as $generator) {
        file_put_contents($root.'/'.$generator, "#!/bin/sh\nexit 0\n");
        chmod($root.'/'.$generator, 0755);
    }
    $path = 'apps/gateway/app/Data/Analytics/AnalyticsCredentialsData.php';
    mkdir(dirname($root.'/'.$path), 0777, true);
    $source = file_get_contents(dirname(__DIR__, 4).'/'.$path);
    expect($source)->toBeString();
    file_put_contents($root.'/'.$path, $source);

    $report = new DocsImpact($root)->report(null, [$path]);

    expect($report['verdict'])->toBe('no_docs_change')
        ->and($report['errors'])->toBe([])
        ->and(collect($report['surfaces'])->pluck('owner'))->not->toContain('unowned')
        ->and(collect($report['surfaces_handled_by_generator'])->pluck('generator')->all())->toBe(['bin/api-fixtures', 'bin/docs-openapi', 'bin/mcp-tools'])
        ->and(collect($report['surfaces_handled_by_generator'])->pluck('status')->unique()->all())->toBe(['passed']);
});

it('docs impact clears removed API diagnostics when generators pass but retains them when a generator fails', function (): void {
    $root = docsImpactFixture();
    mkdir($root.'/bin', 0777, true);
    foreach (['bin/docs-openapi', 'bin/api-fixtures', 'bin/mcp-tools'] as $generator) {
        file_put_contents($root.'/'.$generator, "#!/bin/sh\nexit 0\n");
        chmod($root.'/'.$generator, 0755);
    }
    $path = 'apps/gateway/app/Data/Analytics/AnalyticsCredentialsData.php';
    mkdir(dirname($root.'/'.$path), 0777, true);
    file_put_contents($root.'/'.$path, "<?php return ['errorCode' => 'example.api_failure'];\n");
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm baseline');
    exec('git -C '.escapeshellarg($root).' rm -q '.escapeshellarg($path));

    $handled = new DocsImpact($root)->report('HEAD', [$path]);
    file_put_contents($root.'/bin/api-fixtures', "#!/bin/sh\nexit 1\n");
    $failed = new DocsImpact($root)->report('HEAD', [$path]);

    expect($handled['verdict'])->toBe('no_docs_change')
        ->and($handled['errors'])->toBe([])
        ->and($failed['verdict'])->toBe('docs_required')
        ->and($failed['errors'])->toContain('Removed from current source: Unable to map API source ['.$path.'] to an OpenAPI operation or schema owner.')
        ->and($failed['errors'])->toContain('Removed from current source: Unowned error identifier [example.api_failure] in '.$path.':1.');
});
