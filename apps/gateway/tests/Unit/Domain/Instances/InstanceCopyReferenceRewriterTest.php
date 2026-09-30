<?php

declare(strict_types=1);

use App\Domain\Instances\Copy\InstanceCopyReferenceRewriter;

it('rewrites a source path inside another value and leaves a longer name', function (): void {
    $rewriter = new InstanceCopyReferenceRewriter;
    $source = '/apps/p/feature';
    $target = '/apps/p/copy';

    expect($rewriter->rewrite($source.'/database/database.sqlite', $source, $target, '', ''))
        ->toBe($target.'/database/database.sqlite')
        ->and($rewriter->rewrite('prefix '.$source.'/storage', $source, $target, '', ''))
        ->toBe('prefix '.$target.'/storage')
        ->and($rewriter->rewrite($source, $source, $target, '', ''))
        ->toBe($target)
        ->and($rewriter->rewrite('"'.$source.'"', $source, $target, '', ''))
        ->toBe('"'.$target.'"')
        ->and($rewriter->rewrite($source.'-two/file', $source, $target, '', ''))
        ->toBe($source.'-two/file')
        ->and($rewriter->rewrite($source.'.sqlite', $source, $target, '', ''))
        ->toBe($source.'.sqlite')
        ->and($rewriter->rewrite($source.'_old', $source, $target, '', ''))
        ->toBe($source.'_old');
});

it('rewrites a whole host and leaves a host that only contains it', function (): void {
    $rewriter = new InstanceCopyReferenceRewriter;
    $source = 'feature.example.test';
    $target = 'copy.example.test';

    expect($rewriter->rewrite('https://'.$source.'/path', '/src', '/dst', $source, $target))
        ->toBe('https://'.$target.'/path')
        ->and($rewriter->rewrite($source.':443', '/src', '/dst', $source, $target))
        ->toBe($target.':443')
        ->and($rewriter->rewrite($source.'.other', '/src', '/dst', $source, $target))
        ->toBe($source.'.other')
        ->and($rewriter->rewrite('not'.$source, '/src', '/dst', $source, $target))
        ->toBe('not'.$source)
        ->and($rewriter->rewrite('api.'.$source, '/src', '/dst', $source, $target))
        ->toBe('api.'.$source);
});

it('recognises a database path inside the source checkout', function (): void {
    $rewriter = new InstanceCopyReferenceRewriter;
    $checkout = '/apps/p/feature';

    expect($rewriter->isInsideCheckout($checkout.'/database/database.sqlite', $checkout))->toBeTrue()
        ->and($rewriter->isInsideCheckout($checkout, $checkout))->toBeTrue()
        ->and($rewriter->isInsideCheckout($checkout.'-two/database/database.sqlite', $checkout))->toBeFalse()
        ->and($rewriter->isInsideCheckout($checkout.'.sqlite', $checkout))->toBeFalse()
        ->and($rewriter->isInsideCheckout('/var/lib/shared.sqlite', $checkout))->toBeFalse()
        ->and($rewriter->isInsideCheckout($checkout.'/file', ''))->toBeFalse();
});

it('does not rewrite a sibling checkout twice', function (): void {
    $rewriter = new InstanceCopyReferenceRewriter;
    $source = '/srv/orbit/apps/acme/default';
    $target = '/srv/orbit/apps/acme/default-two';
    $once = $rewriter->rewrite($source.'/database/database.sqlite', $source, $target, 'default.acme.test', 'default-two.acme.test');

    expect($once)->toBe($target.'/database/database.sqlite')
        ->and($rewriter->rewrite($once, $source, $target, 'default.acme.test', 'default-two.acme.test'))
        ->toBe($once);
});
