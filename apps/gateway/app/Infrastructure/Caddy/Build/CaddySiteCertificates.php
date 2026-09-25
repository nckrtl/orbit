<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use Carbon\CarbonImmutable;

/**
 * Records, per Node, that a role's certificate is on the Node. `caddy validate` loads every certificate a
 * Caddyfile names, so a role site renders only once its publisher recorded the certificate. A build that
 * runs while the role's row already serves, for example during its first convergence or a relocation,
 * then leaves the site out instead of failing validation for every site on the Node.
 */
final readonly class CaddySiteCertificates
{
    public const string Websocket = 'websocket';

    public const string Analytics = 'analytics';

    public const string ProxyCli = 'proxycli';

    public const string Metrics = 'metrics';

    public const string KeyPrefix = 'caddy.certificate.';

    public function __construct(
        private SettingRepository $settings = new SettingRepository,
    ) {}

    public function published(int $nodeId, string $site): bool
    {
        return $this->settings->get($this->scope($nodeId), self::KeyPrefix.$site) !== null;
    }

    /** After the certificate step placed the certificate on the Node, before the build that names it. */
    public function record(int $nodeId, string $site): void
    {
        $this->settings->put($this->scope($nodeId), self::KeyPrefix.$site, CarbonImmutable::now('UTC')->toIso8601String());
    }

    /** After the certificate step removed the certificate, or when the Node can no longer be reached. */
    public function forget(int $nodeId, string $site): void
    {
        $this->settings->delete($this->scope($nodeId), self::KeyPrefix.$site);
    }

    private function scope(int $nodeId): SettingScope
    {
        return new SettingScope(SettingScopeType::Node, $nodeId);
    }
}
