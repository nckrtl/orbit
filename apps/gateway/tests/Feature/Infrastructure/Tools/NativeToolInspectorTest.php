<?php

declare(strict_types=1);

use App\Domain\Tools\ToolInspectionException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Infrastructure\Tools\NativeToolInspector;
use App\Models\Tool;
use Tests\Support\FakeToolManager;

it('returns bounded installed state and normalized version', function (): void {
    $manager = new FakeToolManager;
    $manager->installedVersions = ['1.2.3'];
    $tool = Tool::make(['package' => 'example']);
    $node = \App\Models\Node::make();
    $node->setAttribute('id', 1);
    $record = \App\Models\ToolManagerRecord::make(['node_id' => 1, 'name' => ToolManagerName::Apt]);
    $tool->setRelation('node', $node);
    $tool->setRelation('manager', $record);

    $data = new NativeToolInspector(new ToolManagerRegistry([$manager]))->inspect($tool);

    expect($data->installed)->toBeTrue()->and($data->normalizedVersion)->toBe('1.2.3');
});

it('returns absent state when the manager reports no installed version', function (): void {
    $manager = new FakeToolManager;
    $manager->installedVersions = [null];
    $tool = Tool::make(['package' => 'example']);
    $node = \App\Models\Node::make();
    $node->setAttribute('id', 1);
    $record = \App\Models\ToolManagerRecord::make(['node_id' => 1, 'name' => ToolManagerName::Apt]);
    $tool->setRelation('node', $node);
    $tool->setRelation('manager', $record);

    $data = new NativeToolInspector(new ToolManagerRegistry([$manager]))->inspect($tool);

    expect($data->installed)->toBeFalse()->and($data->normalizedVersion)->toBeNull();
});

it('fails closed when ownership is invalid', function (): void {
    $tool = Tool::make(['package' => 'example']);
    $node = \App\Models\Node::make();
    $node->setAttribute('id', 1);
    $tool->setRelation('node', $node);
    $tool->setRelation('manager', \App\Models\ToolManagerRecord::make([
        'node_id' => 2,
        'name' => ToolManagerName::Apt,
    ]));

    expect(fn (): mixed => new NativeToolInspector(new ToolManagerRegistry([new FakeToolManager]))->inspect($tool))
        ->toThrow(ToolInspectionException::class, '');
});

it('fails closed for unsupported, unknown, throwing, and unnormalizable managers', function (): void {
    $node = \App\Models\Node::make();
    $node->setAttribute('id', 1);
    $record = \App\Models\ToolManagerRecord::make(['node_id' => 1, 'name' => ToolManagerName::Apt]);
    $tool = Tool::make(['package' => 'example']);
    $tool->setRelation('node', $node);
    $tool->setRelation('manager', $record);
    $manager = new FakeToolManager;
    $manager->supports = false;
    expect(fn (): mixed => new NativeToolInspector(new ToolManagerRegistry([$manager]))->inspect($tool))
        ->toThrow(ToolInspectionException::class);
    $manager->supports = true;
    $manager->installedVersions = [new RuntimeException('secret-output')];
    expect(fn (): mixed => new NativeToolInspector(new ToolManagerRegistry([$manager]))->inspect($tool))
        ->toThrow(ToolInspectionException::class, '');
    $manager->installedVersions = ['not-a-version'];
    expect(fn (): mixed => new NativeToolInspector(new ToolManagerRegistry([$manager]))->inspect($tool))
        ->toThrow(ToolInspectionException::class);
});

it('uses only the read-only installed version interaction and ignores stored version', function (): void {
    $manager = new FakeToolManager;
    $manager->installedVersions = ['1.2.3'];
    $tool = Tool::make(['package' => 'example', 'installed_version' => '99.99.99']);
    $node = \App\Models\Node::make();
    $node->setAttribute('id', 1);
    $tool->setRelation('node', $node);
    $tool->setRelation('manager', \App\Models\ToolManagerRecord::make([
        'node_id' => 1,
        'name' => ToolManagerName::Apt,
    ]));
    $data = new NativeToolInspector(new ToolManagerRegistry([$manager]))->inspect($tool);
    expect($data->normalizedVersion)->toBe('1.2.3')->and($manager->calls)->toBe(['installedVersion']);
});
