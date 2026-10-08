<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Closure;

final readonly class GatewayCheckoutAccessConverger
{
    /** @var Closure(string): ?string */
    private Closure $currentRelease;

    /**
     * @param  (Closure(string): ?string)|null  $currentRelease  The release id the checkout path's link names, from the file system by default.
     */
    public function __construct(
        private ProcessRunner $processes,
        private string $checkoutPath,
        ?Closure $currentRelease = null,
    ) {
        $this->currentRelease = $currentRelease ?? static fn (string $checkout): ?string => new GatewayReleaseLayout($checkout)->currentReleaseId();
    }

    public function converge(): void
    {
        $this->validate();
        $layout = $this->releaseLayout();

        if ($layout instanceof GatewayReleaseLayout) {
            $this->convergeReleaseLayout($layout);

            return;
        }

        $directories = $this->ancestors();
        $this->run('gateway-checkout-access', 'gateway.checkout_access_failed', [
            'sudo',
            'chown',
            'orbit:caddy',
            ...$directories,
            $this->checkoutPath.'/public',
        ]);
        $this->run('gateway-checkout-access', 'gateway.checkout_access_failed', [
            'sudo',
            'chmod',
            '0710',
            ...$directories,
        ]);
        $this->run('gateway-checkout-access', 'gateway.checkout_access_failed', [
            'sudo',
            'chmod',
            '0750',
            $this->checkoutPath.'/public',
        ]);
        $this->run(
            step: 'gateway-public-access',
            errorCode: 'gateway.checkout_access_failed',
            arguments: ['sudo', 'bash', '-seu', '--', $this->checkoutPath.'/public'],
            input: <<<'BASH'
                public=$1
                test ! -L "$public"
                test -d "$public"
                find -P "$public" -type d -exec chown --no-dereference orbit:caddy -- {} + -exec chmod 0750 -- {} +
                find -P "$public" -type f -exec chown --no-dereference orbit:caddy -- {} + -exec chmod 0640 -- {} +
                BASH,
        );
        $this->run('gateway-environment-protect', 'gateway.environment_protection_failed', [
            'sudo',
            'chmod',
            '0600',
            $this->checkoutPath.'/.env',
        ]);
    }

    /**
     * In the release layout each release got its access when it was prepared, and its directories
     * are read-only. Only the paths every release goes through are converged here: the home and
     * releases directories, and the shared env file through the release's link.
     */
    private function convergeReleaseLayout(GatewayReleaseLayout $layout): void
    {
        $directories = [$layout->basePath(), $layout->releasesPath()];
        $this->run('gateway-checkout-access', 'gateway.checkout_access_failed', ['sudo', 'chown', 'orbit:caddy', ...$directories]);
        $this->run('gateway-checkout-access', 'gateway.checkout_access_failed', ['sudo', 'chmod', '0710', ...$directories]);
        $this->run('gateway-environment-protect', 'gateway.environment_protection_failed', ['sudo', 'chmod', '0600', $layout->environmentPath()]);
    }

    /** The release layout when the checkout path goes through a link to a release, else null. */
    private function releaseLayout(): ?GatewayReleaseLayout
    {
        try {
            return ($this->currentRelease)($this->checkoutPath) === null ? null : new GatewayReleaseLayout($this->checkoutPath);
        } catch (GatewayReleaseException) {
            return null;
        }
    }

    /**
     * Refuses a checkout path outside the managed user's home, or one that resolves elsewhere,
     * before any step changes the machine. In the release layout the path resolves to the
     * current release instead.
     */
    public function validate(): void
    {
        $components = explode('/', $this->checkoutPath);

        if (
            rtrim(string: $this->checkoutPath, characters: '/') !== $this->checkoutPath
            || ! str_starts_with($this->checkoutPath, '/home/orbit/')
            || str_contains($this->checkoutPath, "\0")
            || str_contains($this->checkoutPath, "\n")
            || in_array(needle: '..', haystack: $components, strict: true)
            || in_array(needle: '.', haystack: $components, strict: true)
        ) {
            throw $this->failure(
                'gateway-checkout-validate',
                'gateway.checkout_invalid',
                "Gateway checkout path [{$this->checkoutPath}] is unsafe.",
            );
        }

        $layout = $this->releaseLayout();
        $release = $layout instanceof GatewayReleaseLayout ? ($this->currentRelease)($this->checkoutPath) : null;
        $expected = $layout instanceof GatewayReleaseLayout && $release !== null
            ? $layout->releaseApplicationPath($release)
            : $this->checkoutPath;

        $this->run(
            step: 'gateway-checkout-validate',
            errorCode: 'gateway.checkout_invalid',
            arguments: ['sudo', 'bash', '-seu', '--', $this->checkoutPath, $expected],
            input: <<<'BASH'
                checkout=$1
                expected=$2
                resolved=$(readlink -f -- "$checkout")

                if [ "$resolved" != "$expected" ]; then
                    exit 1
                fi

                case "$resolved" in
                    /home/orbit/*) ;;
                    *) exit 1 ;;
                esac

                test ! -L "$resolved/public"
                test -d "$resolved/public"
                test -f "$resolved/.env"
                BASH,
        );
    }

    /** @return non-empty-list<string> */
    private function ancestors(): array
    {
        $relative = substr($this->checkoutPath, strlen('/home/orbit/'));
        $directories = ['/home/orbit'];
        $path = '/home/orbit';

        foreach (explode('/', $relative) as $component) {
            $path .= "/{$component}";
            $directories[] = $path;
        }

        return $directories;
    }

    /** @param non-empty-list<string> $arguments */
    private function run(string $step, string $errorCode, array $arguments, ?string $input = null): void
    {
        $result = $this->processes->run(new ProcessInvocation(
            arguments: $arguments,
            timeout: 60.0,
            input: $input,
        ));

        if (! $result->succeeded()) {
            throw $this->failure($step, $errorCode, "Gateway checkout convergence step [{$step}] failed.", $result);
        }
    }

    private function failure(
        string $step,
        string $errorCode,
        string $message,
        ?CommandResult $result = null,
    ): NodeProvisioningException {
        return new NodeProvisioningException(
            step: $step,
            errorCode: $errorCode,
            message: $message,
            result: $result,
        );
    }
}
