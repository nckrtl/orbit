<?php

declare(strict_types=1);

use App\Documentation\DocumentationContextIndexBuilder;
use App\Documentation\DocumentationContextMetadataExtractor;
use App\Documentation\DocumentationRepository;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->temporaryRoot = sys_get_temp_dir().'/orbit-docs-'.bin2hex(random_bytes(8));
    $this->docsPath = $this->temporaryRoot.'/docs';
    new Filesystem()->makeDirectory($this->docsPath.'/decisions', 0777, true);

    file_put_contents($this->docsPath.'/concepts.md', <<<'MARKDOWN'
        # Concepts

        - **Cluster** — Groups nodes and workloads.
        - **Gateway** — Owns durable state.
        MARKDOWN);
    file_put_contents($this->docsPath.'/mission.md', <<<'MARKDOWN'
        # Mission

        The Gateway coordinates each Cluster through apps/gateway.
        MARKDOWN);
    file_put_contents($this->docsPath.'/decisions/0001-cluster.md', <<<'MARKDOWN'
        # ADR 0001: Define a Cluster

        The Gateway owns Cluster state in apps/gateway.
        MARKDOWN);

    $repository = new DocumentationRepository(
        $this->docsPath,
        $this->docsPath.'/generated/context.json',
        ['apps/docs', 'apps/gateway'],
    );
    $this->builder = new DocumentationContextIndexBuilder(
        $repository,
        new DocumentationContextMetadataExtractor($repository),
    );
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->temporaryRoot);
});

it('builds deterministic document metadata from markdown', function (): void {
    $documents = $this->builder->build()->documents;
    $decision = collect($documents)->firstWhere('path', 'docs/decisions/0001-cluster.md');

    expect($documents)
        ->toHaveCount(3)
        ->and($decision)
        ->not
        ->toBeNull()
        ->and($decision->title)
        ->toBe('ADR 0001: Define a Cluster')
        ->and($decision->kind)
        ->toBe('decision')
        ->and($decision->components)
        ->toBe(['apps/gateway'])
        ->and($decision->concepts)
        ->toBe(['Cluster', 'Gateway'])
        ->and($decision->governingAdrs)
        ->toBe(['0001']);
});

it('detects whether the committed index matches its sources', function (): void {
    expect($this->builder->isFresh())->toBeFalse();

    $this->builder->write();

    expect($this->builder->isFresh())->toBeTrue();

    file_put_contents($this->docsPath.'/mission.md', "\nTooling lives in apps/docs.\n", FILE_APPEND);

    expect($this->builder->isFresh())->toBeFalse();
});
