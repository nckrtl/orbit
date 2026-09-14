<?php

declare(strict_types=1);

use App\Documentation\DocumentationRepository;
use App\Librarian\Rules\MintlifyLinksRule;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/orbit-mintlify-'.bin2hex(random_bytes(8));
    new Filesystem()->makeDirectory($this->root.'/reference', 0777, true);
    $this->rule = new MintlifyLinksRule(new DocumentationRepository($this->root, $this->root.'/generated/context.json', []));
    file_put_contents($this->root.'/reference/apps.md', '# Apps');
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->root);
});

it('accepts Mintlify navigation and local Markdown and MDX links', function (): void {
    file_put_contents($this->root.'/docs.json', '{"navigation":{"groups":[{"pages":["index",{"group":"Reference","pages":["reference/apps"]}]}]}}');
    file_put_contents($this->root.'/index.mdx', <<<'MDX'
        ---
        title: "Orbit"
        ---

        <CardGroup cols={2}>
          <Card title="Apps" href="/reference/apps">Create an App instance.</Card>
          <Card title="Home" href="/">Start here.</Card>
        </CardGroup>

        [Apps](/reference/apps#overview)
        [Apps](/reference/apps?mode=example#overview)
        [Home](/index)
        [Web](https://example.com)
        [CDN](//example.com/image.png)
        `href="/example-only"`

        ```mdx
        <Card href="/example-only" />
        [Example](missing.md)
        ```
        MDX);
    file_put_contents($this->root.'/reference/link(test).md', '# Link');
    file_put_contents($this->root.'/reference/links.md', '[Nested](/reference/link(test) "Title")');

    expect($this->rule->check())->toBe([]);
});

it('rejects missing navigation pages and card or Markdown destinations', function (): void {
    file_put_contents($this->root.'/docs.json', '{"navigation":{"groups":[{"pages":["missing-page"]}]}}');
    file_put_contents($this->root.'/index.mdx', "<Card href=\"/missing-card\" />\n[Missing](reference/missing.md)\n");

    $findings = $this->rule->check();

    expect($findings)->toHaveCount(3)
        ->and(array_column($findings, 'path'))->toBe(['docs/index.mdx', 'docs/index.mdx', 'docs/docs.json'])
        ->and(array_column($findings, 'line'))->toBe([1, 2, null]);
});

it('rejects invalid navigation configuration', function (string $config): void {
    file_put_contents($this->root.'/docs.json', $config);

    expect($this->rule->check())->toHaveCount(1);
})->with(['{invalid', '{}', 'null']);

it('checks local links without requiring a Mintlify configuration in fixtures', function (): void {
    file_put_contents($this->root.'/reference/links.md', '[Missing](missing.md)');

    expect($this->rule->check())->toHaveCount(1);
});

it('rejects repository page URLs that would break Mintlify navigation', function (string $target): void {
    file_put_contents($this->root.'/docs.json', '{"navigation":{"groups":[{"pages":["reference/apps"]}]}}');
    file_put_contents($this->root.'/index.mdx', "[Apps]({$target})");

    $findings = $this->rule->check();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('root-relative page URL');
})->with(['reference/apps.md', '/reference/apps.md#overview', 'reference/apps']);
