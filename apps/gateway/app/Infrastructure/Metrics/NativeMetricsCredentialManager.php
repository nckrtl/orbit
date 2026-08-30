<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Data\Metrics\MetricsCredentialsData;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsCredentialRuntime;
use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

final readonly class NativeMetricsCredentialManager implements MetricsCredentialManager
{
    public const string ActivePasswordKey = 'metrics.grafana.admin_password';

    public const string PendingPasswordKey = 'metrics.grafana.pending_admin_password';

    private const int PasswordLength = 32;

    private const string Url = 'https://metrics.orbit';

    private const string Username = 'admin';

    public function __construct(
        private SettingRepository $settings,
        private MetricsCredentialRuntime $runtime,
    ) {}

    public function passwordForConvergence(Node $node): string
    {
        $scope = $this->scope($node);
        $password = $this->settings->get($scope, self::ActivePasswordKey);

        if (is_string($password) && $password !== '') {
            return $password;
        }

        $password = Str::random(self::PasswordLength);
        $this->settings->put(
            $scope,
            self::ActivePasswordKey,
            $password,
            SettingValueProtection::Secret,
        );

        return $password;
    }

    public function verifyActive(Node $node): void
    {
        $password = $this->activePassword($node);

        if (! $this->runtime->verify($node, $password)) {
            throw new ResourceOperationException(
                'metrics.credentials_unverified',
                'Grafana rejected the active credential.',
                502,
            );
        }
    }

    public function purge(Node $node): void
    {
        $scope = $this->scope($node);

        DB::transaction(function () use ($scope): void {
            $this->settings->delete($scope, self::ActivePasswordKey);
            $this->settings->delete($scope, self::PendingPasswordKey);
        });
    }

    public function credentials(): MetricsCredentialsData
    {
        $node = $this->assignedNode();
        $password = $this->activePassword($node);

        if (! $this->runtime->verify($node, $password)) {
            throw new ResourceOperationException(
                'metrics.credentials_unverified',
                'Grafana rejected the active credential.',
                502,
            );
        }

        return $this->data($password);
    }

    public function reset(): MetricsCredentialsData
    {
        $node = $this->assignedNode();
        $scope = $this->scope($node);
        $pending = $this->settings->get($scope, self::PendingPasswordKey);
        $isRetry = is_string($pending) && $pending !== '';

        if (! $isRetry) {
            $pending = Str::random(self::PasswordLength);
            $this->settings->put(
                $scope,
                self::PendingPasswordKey,
                $pending,
                SettingValueProtection::Secret,
            );
        }

        if (! $isRetry || ! $this->runtime->verify($node, $pending)) {
            $this->runtime->apply($node, $this->activePassword($node), $pending);

            if (! $this->runtime->verify($node, $pending)) {
                throw new ResourceOperationException(
                    'metrics.credentials_reset_unverified',
                    'Grafana did not verify the pending credential.',
                    502,
                );
            }
        }

        DB::transaction(function () use ($scope, $pending): void {
            $this->settings->put(
                $scope,
                self::ActivePasswordKey,
                $pending,
                SettingValueProtection::Secret,
            );
            $this->settings->delete($scope, self::PendingPasswordKey);
        });

        return $this->data($pending);
    }

    private function assignedNode(): Node
    {
        $assignments = NodeRole::query()
            ->where('role', RoleName::Metrics->value)
            ->with('node')
            ->limit(2)
            ->get();

        if ($assignments->isEmpty()) {
            throw new ResourceOperationException('metrics.assignment_missing', 'Metrics is not assigned.', 409);
        }

        if ($assignments->count() !== 1) {
            throw new ResourceOperationException(
                'metrics.assignment_drift',
                'Metrics has more than one assignment.',
                409,
            );
        }

        $assignment = $assignments->sole();
        $node = $assignment->node;

        if ($node->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('metrics.node_inactive', 'The Metrics node is not active.', 409);
        }

        return $node;
    }

    private function activePassword(Node $node): string
    {
        $password = $this->settings->get($this->scope($node), self::ActivePasswordKey);

        if (! is_string($password) || $password === '') {
            throw new ResourceOperationException(
                'metrics.credentials_missing',
                'Grafana credentials are unavailable.',
                422,
            );
        }

        return $password;
    }

    private function scope(Node $node): SettingScope
    {
        return new SettingScope(SettingScopeType::Node, $node->id);
    }

    private function data(#[SensitiveParameter] string $password): MetricsCredentialsData
    {
        return new MetricsCredentialsData(
            url: self::Url,
            username: self::Username,
            password: $password,
        );
    }
}
