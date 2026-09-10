<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstancePhpVersionCatalog;
use App\Domain\AppInstances\ComposerSourceClassifier;
use App\Domain\Nodes\ManagedUserAccount;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\AppDevSite;

it('owns a finite descending AppInstance PHP candidate catalog', function (): void {
    expect(new AppInstancePhpVersionCatalog()->versions())->toBe(['8.5', '8.4']);
});

it('selects the highest compatible candidate', function (?string $constraint, string $version): void {
    expect(new AppInstancePhpVersionCatalog()->select($constraint))->toBe($version);
})->with([
    'no constraint' => [null, '8.5'],
    'both candidates' => ['^8.4', '8.5'],
    '8.4 only' => ['~8.4.0', '8.4'],
    'bounded above' => ['>=8.4 <8.5', '8.4'],
]);

it('refuses invalid and unsupported source constraints', function (string $constraint): void {
    $classifier = new ComposerSourceClassifier(new AppInstancePhpVersionCatalog);

    expect(fn () => $classifier->classify(json_encode(['require' => [
        'php' => $constraint,
    ]], JSON_THROW_ON_ERROR), 'absent'))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->step)
                ->toBe('source-classification')
                ->and($exception->errorCode)
                ->toBe('app-dev.php_version_unsupported');
        });
})->with([
    'invalid' => ['not a constraint'],
    'below catalog' => ['<8.4'],
    'between candidates' => ['>8.4 <8.5'],
    'above catalog' => ['>8.5'],
]);

it('classifies Composer metadata as PHP and metadata absence as non-PHP', function (): void {
    $classifier = new ComposerSourceClassifier(new AppInstancePhpVersionCatalog);

    expect($classifier->classify('{"name":"acme/site"}', 'absent'))
        ->phpVersion->toBe('8.5')
        ->laravel->toBeFalse();
});

it('renders only the selected production PHP site with its recorded user home pool and socket', function (): void {
    $php = new AppDevSite(
        nodeId: 1,
        nodeAddress: '10.44.0.10',
        scope: 'app-instance-7',
        checkoutPath: '/home/orbit-app-3',
        documentRoot: 'current/public',
        phpVersion: '8.5',
        hostname: 'app.example.test',
        environment: 'production',
        productionUser: 'orbit-app-3',
        productionHome: '/home/orbit-app-3',
    );
    $nonPhp = new AppDevSite(
        nodeId: 1,
        nodeAddress: '10.44.0.10',
        scope: 'app-instance-8',
        checkoutPath: '/home/orbit-app-4',
        documentRoot: 'public',
        phpVersion: null,
        hostname: 'static.example.test',
        environment: 'production',
        productionUser: 'orbit-app-4',
        productionHome: '/home/orbit-app-4',
    );
    $selected = collect([$php, $nonPhp])
        ->filter(static fn (AppDevSite $site): bool => $site->phpVersion !== null)
        ->values();
    $configuration = new AppDevPhpFpmConfigRenderer()->render(
        $selected,
        new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
    );
    $caddy = new AppDevCaddyConfigRenderer()->render(collect([$php, $nonPhp]));

    expect($selected)
        ->toHaveCount(1)
        ->and($configuration)
        ->toContain(
            '[orbit-app-instance-7]',
            'user = orbit-app-3',
            'group = orbit-app-3',
            'listen = /run/php/orbit-app-instance-7.sock',
            'env[HOME] = /home/orbit-app-3',
        )
        ->not
        ->toContain('app-instance-8', 'composer install', 'artisan')
        ->and($caddy)
        ->toContain(
            'root * /home/orbit-app-3/current/public',
            'php_fastcgi unix//run/php/orbit-app-instance-7.sock',
            'resolve_root_symlink',
            'root * /home/orbit-app-4/public',
        );
});
