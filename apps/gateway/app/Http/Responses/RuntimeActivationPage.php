<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\AppInstance;
use Illuminate\Http\Response;

final readonly class RuntimeActivationPage
{
    public const string ACTIVATION_STATE_HEADER = 'X-Orbit-Runtime-Activation-State';

    public const string STATE_PENDING = 'pending';

    public const string STATE_FAILED = 'failed';

    public const int POLL_INTERVAL_SECONDS = 1;

    private const string MARK_PATH = 'M50 25C77.6143 25 100 36.1929 100 50C99.9996 63.8069 77.614 75 50 75C22.386 75 0.000366987 63.8069 0 50C0 36.1929 22.3858 25 50 25ZM49.7764 32.0107C32.7857 32.0108 15.7344 38.9923 15.7344 46.9102C15.7346 54.8279 28.3485 61.2461 49.5654 61.2461C70.7823 61.2461 83.3962 54.8279 83.3965 46.9102C83.3965 38.9923 66.7672 32.0107 49.7764 32.0107Z';

    public function progress(AppInstance $instance, string $originalUri): Response
    {
        return $this->render($instance, $originalUri, failed: false, failureReason: null, status: 401);
    }

    public function failed(string $message, AppInstance $instance, string $originalUri): Response
    {
        $reason = trim($message);

        return $this->render(
            $instance,
            $originalUri,
            failed: true,
            failureReason: $reason === '' ? null : $reason,
            status: 503,
        );
    }

    public function ready(): Response
    {
        return response('', 200);
    }

    private function render(
        AppInstance $instance,
        string $originalUri,
        bool $failed,
        ?string $failureReason,
        int $status,
    ): Response {
        $uri = $this->safeOriginalUri($originalUri);
        $scriptNonce = $failed ? null : bin2hex(random_bytes(16));

        $response = response()
            ->view('runtime-activation', [
                'name' => $this->displayName($instance),
                'failed' => $failed,
                'failureReason' => $failureReason,
                'refreshUri' => $uri,
                'retryUri' => $this->retryUri($uri),
                'scriptNonce' => $scriptNonce,
                'pollIntervalMs' => self::POLL_INTERVAL_SECONDS * 1000,
                'activationStateHeader' => self::ACTIVATION_STATE_HEADER,
                'pendingState' => self::STATE_PENDING,
                'keyframes' => $this->keyframes(),
                'maskDataUri' => $this->maskDataUri(),
                'faviconDataUri' => $this->faviconDataUri(),
                'trailStartPct' => 64,
                'markPath' => self::MARK_PATH,
            ], $status)
            ->header('Cache-Control', 'no-store, private')
            ->header(
                self::ACTIVATION_STATE_HEADER,
                $failed ? self::STATE_FAILED : self::STATE_PENDING,
            )
            ->header('Content-Security-Policy', $this->contentSecurityPolicy($scriptNonce))
            ->header('X-Robots-Tag', 'noindex, nofollow');

        if (! $failed) {
            $response->header('Retry-After', (string) self::POLL_INTERVAL_SECONDS);
        }

        return $response;
    }

    private function displayName(AppInstance $instance): string
    {
        $domain = $instance->registration_route_domain;

        if (is_string($domain) && trim($domain) !== '') {
            return trim($domain);
        }

        $instance->loadMissing('app');
        $name = $instance->app->name;

        return $name !== '' ? $name : $instance->name;
    }

    /**
     * Keyframe percentages follow time along the Orbit ellipse, not uniform degrees.
     * CSS rotate is the polar angle. Screen speed is inverse to depth, so the head
     * is slowest at the top and fastest at the bottom.
     *
     * @return list<array{pct: string, deg: string}>
     */
    private function keyframes(): array
    {
        $rx = 41.916;
        $ry = 19.809;
        $samples = 128;
        $strength = 0.25;
        $time = [0.0];
        $previousTheta = -M_PI / 2;

        $timeDensity = static function (float $theta) use ($rx, $ry, $strength): float {
            $sin = sin($theta);
            $cos = cos($theta);
            $denominator = ($rx * $rx * $sin * $sin) + ($ry * $ry * $cos * $cos);
            $squareRoot = sqrt($denominator);
            $radius = ($rx * $ry) / $squareRoot;
            $radiusDerivative = ($rx * $ry) * (($rx * $rx) - ($ry * $ry)) * $sin * $cos / ($denominator * $squareRoot);

            return hypot($radius, $radiusDerivative) * exp($strength * (-$sin));
        };

        for ($sample = 1; $sample <= $samples; $sample++) {
            $theta = -M_PI / 2 + (2 * M_PI * ($sample / $samples));
            $step = $theta - $previousTheta;
            $time[$sample] = $time[$sample - 1] + (0.5 * ($timeDensity($previousTheta) + $timeDensity($theta)) * $step);
            $previousTheta = $theta;
        }

        $total = $time[$samples];
        $frames = [];

        for ($sample = 0; $sample <= $samples; $sample++) {
            $percent = match (true) {
                $sample === 0 => 0.0,
                $sample === $samples => 100.0,
                default => 100.0 * ($time[$sample] / $total),
            };

            $frames[] = [
                'pct' => number_format($percent, 4, '.', ''),
                'deg' => number_format(360.0 * ($sample / $samples), 4, '.', ''),
            ];
        }

        return $frames;
    }

    private function maskDataUri(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 25 100 50">'
            .'<path fill="white" d="'.self::MARK_PATH.'"/></svg>';

        return 'data:image/svg+xml,'.rawurlencode($svg);
    }

    private function faviconDataUri(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" rx="10" fill="#000"/>'
            .'<path fill="#fff" transform="translate(8 8) scale(.48)" d="'.self::MARK_PATH.'"/>'
            .'</svg>';

        return 'data:image/svg+xml,'.rawurlencode($svg);
    }

    private function contentSecurityPolicy(?string $scriptNonce): string
    {
        $directives = [
            "default-src 'none'",
            "style-src 'unsafe-inline'",
            'img-src data:',
            "base-uri 'none'",
            "frame-ancestors 'none'",
        ];

        if (is_string($scriptNonce) && $scriptNonce !== '') {
            $directives[] = "script-src 'nonce-{$scriptNonce}'";
            $directives[] = "connect-src 'self'";
        }

        return implode('; ', $directives);
    }

    private function safeOriginalUri(string $uri): string
    {
        $uri = trim($uri);

        if ($uri === '' || ! str_starts_with($uri, '/') || str_starts_with($uri, '//')) {
            return '/';
        }

        return str_replace(
            search: ["\r", "\n", '"', '<', '>'],
            replace: '',
            subject: $uri,
        );
    }

    private function retryUri(string $uri): string
    {
        return $uri.(str_contains($uri, '?') ? '&' : '?').'orbit-wake-retry=1';
    }
}
