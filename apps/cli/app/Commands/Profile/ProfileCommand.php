<?php

declare(strict_types=1);

namespace App\Commands\Profile;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Services\Profile\ProfileHumanRenderer;
use App\Services\Profile\ProfileInput;
use App\Services\Profile\ProfileInputFailure;
use App\Services\Profile\ProfileInputResolver;
use App\Services\Profile\ProfileRequestProfiler;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\AppInstances\ShowAppInstanceRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;

final class ProfileCommand extends GatewayCommand
{
    /** Tells a development AppInstance's Caddy site this request is a measurement, not traffic. */
    public const string PROBE_HEADER = 'X-Orbit-Probe';

    /** The active profile's Orbit root certificate, set when --instance resolved its URL. */
    private ?string $instanceCaPath = null;

    #[\Override]
    protected $signature = 'profile
        {url? : Absolute HTTP or HTTPS URL to profile}
        {--instance= : Numeric AppInstance ID to profile, using the URL the Gateway records for it}
        {--path= : Path to profile on the --instance URL, defaulting to /}
        {--as-first-user : Authenticate the profiled request as the first user}
        {--user= : Authenticate the profiled request as the given primary key}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Profile one HTTP request from this machine.';

    public function handle(
        ProfileInputResolver $inputResolver,
        ProfileHumanRenderer $renderer,
        ProfileRequestProfiler $profiler,
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $explicitUrl = $this->urlArgument();

        if ($this->option('instance') !== null) {
            if ($explicitUrl !== null) {
                return $this->renderGatewayFailure(
                    'input.invalid',
                    'Pass a URL or --instance, not both.',
                );
            }

            $explicitUrl = $this->instanceUrl($repository, $connectors);

            if ($explicitUrl === null) {
                return self::FAILURE;
            }
        }

        $user = $this->trimmedOption('user');
        $input = $inputResolver->resolve(
            url: $explicitUrl,
            asFirstUser: (bool) $this->option('as-first-user'),
            user: $user,
        );

        if (
            $explicitUrl === null
            && $input instanceof ProfileInputFailure
            && $input->isUrlResolutionFailure()
            && $this->input->isInteractive()
            && $this->option('json') !== true
        ) {
            $input = $inputResolver->resolve(
                url: $this->promptUrl($inputResolver),
                asFirstUser: (bool) $this->option('as-first-user'),
                user: $user,
            );
        }

        if ($input instanceof ProfileInputFailure) {
            return $this->renderProfileFailure($input->code, $input->message, $input->meta);
        }

        return $this->runProfile($input, $renderer, $profiler);
    }

    private function runProfile(
        ProfileInput $input,
        ProfileHumanRenderer $renderer,
        ProfileRequestProfiler $profiler,
    ): int {
        $requestId = (string) Str::uuid();
        $probe = $profiler->profile(
            $input->url,
            $this->profileHeaders($input->authMode, $requestId, $input->user),
            $this->instanceCaPath,
        );
        $request = $probe['request'];

        if ($request['completed'] !== true) {
            return $this->renderProfileFailure(
                'profile.request_failed',
                'Failed to complete profile request.',
                [
                    'origin' => 'caller',
                    'url' => $input->url,
                ],
                [
                    'request' => $request,
                    'timings' => $probe['timings'],
                    'profile_error' => $probe['error'] ?? ['message' => 'Profile request failed.'],
                ],
            );
        }

        $data = [
            ...$probe,
            'source' => 'baseline',
            'instrumented' => false,
            'auth_mode' => $input->authMode,
            'request_id' => $requestId,
            'origin' => 'caller',
        ];

        $summary = $this->extractToolbarSummary($probe['response_headers']);

        if ($summary !== null) {
            $data['source'] = 'baseline+toolbar';
            $data['instrumented'] = true;
            $data['toolbar'] = $summary;
        }

        return $this->renderProfileData($data, $renderer);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderProfileData(array $data, ProfileHumanRenderer $renderer): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($data);

            return self::SUCCESS;
        }

        foreach ($renderer->lines($data) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    private function promptUrl(ProfileInputResolver $inputResolver): ?string
    {
        while (true) {
            $prompted = $this->ask('URL to profile');
            $url = is_string($prompted) && trim($prompted) !== '' ? trim($prompted) : null;

            if ($url === null) {
                return null;
            }

            $message = $inputResolver->urlValidationMessage($url);

            if ($message === null) {
                return $url;
            }

            $this->line($message);
        }
    }

    private function urlArgument(): ?string
    {
        $url = $this->argument('url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function trimmedOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<string, string>
     */
    private function profileHeaders(string $authMode, string $requestId, ?string $user): array
    {
        $headers = [
            'X-REQUEST-ID' => $requestId,
            'X-TOOLBAR-AUTH' => $authMode,
        ];

        if ($this->option('instance') !== null) {
            // A development AppInstance hibernates its own Processes after an idle window, and
            // its Caddy site wakes them for any request that reaches the wake handler. This
            // header tells that site the request is a measurement: do not wake it, and do not
            // count it as the activity that keeps it awake.
            $headers[self::PROBE_HEADER] = '1';
        }

        if ($user !== null) {
            $headers['X-TOOLBAR-USER'] = $user;
        }

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $responseHeaders
     * @return array<string, mixed>|null
     */
    private function extractToolbarSummary(array $responseHeaders): ?array
    {
        $encoded = $responseHeaders['x-toolbar-summary'] ?? null;

        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            return null;
        }

        $summary = json_decode($decoded, associative: true);

        return is_array($summary) ? $summary : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    private function renderProfileFailure(string $code, string $message, array $meta = [], array $data = []): int
    {
        if ($this->option('json') === true) {
            return $this->renderGatewayFailure(
                $code,
                $message,
                details: $this->jsonFailureDetails($code, $meta, $data),
            );
        }

        $this->line($message);

        if ($code === 'profile.request_failed') {
            foreach ($this->profileFailureDiagnosticLines($meta, $data) as $line) {
                $this->line($line);
            }
        }

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function jsonFailureDetails(string $code, array $meta, array $data): array
    {
        if ($code !== 'profile.request_failed') {
            return [];
        }

        $details = [];
        $origin = $meta['origin'] ?? null;
        $url = $meta['url'] ?? null;
        $profileError = $data['profile_error'] ?? null;
        $profileErrorMessage = is_array($profileError) ? $profileError['message'] ?? null : null;

        if (is_string($origin) && $origin !== '') {
            $details['origin'] = $origin;
        }

        if (is_string($url) && $url !== '') {
            $details['url'] = $url;
        }

        if (is_string($profileErrorMessage) && trim($profileErrorMessage) !== '') {
            $details['error'] = $profileErrorMessage;
        }

        return $details;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function profileFailureDiagnosticLines(array $meta, array $data): array
    {
        if (($meta['origin'] ?? null) !== 'caller') {
            return [];
        }

        $lines = ['Origin: caller'];
        $url = $meta['url'] ?? null;

        if (is_string($url) && $url !== '') {
            $lines[] = "URL: {$url}";
        }

        $profileError = $data['profile_error'] ?? null;
        $profileErrorMessage = is_array($profileError) ? $profileError['message'] ?? null : null;

        if (is_string($profileErrorMessage) && trim($profileErrorMessage) !== '') {
            $lines[] = "Error: {$profileErrorMessage}";
        }

        return $lines;
    }

    /** The URL the Gateway records for --instance, with --path appended when given. */
    private function instanceUrl(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): ?string
    {
        $instanceId = filter_var($this->option('instance'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_int($instanceId)) {
            $this->renderGatewayFailure('instance.id_invalid', 'Instance ID must be a positive integer.');

            return null;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return null;
        }

        // An AppInstance's domain presents an Orbit CA leaf, which curl's own bundle cannot
        // chain, so the profiled request verifies against the same root the API calls use.
        $this->instanceCaPath = $this->activeGatewayProfile($repository)?->caPath;

        $instance = $this->sendWithProgress(
            $connector,
            new ShowAppInstanceRequest($instanceId),
            AppInstanceResponse::class,
            ['Show App instance', 'Fetching App instance', 'Fetched App instance'],
            dismiss: true,
        );

        if (! $instance instanceof AppInstanceResponse) {
            return null;
        }

        $base = $instance->url ?? ($instance->domain === null ? null : 'https://'.$instance->domain);

        if ($base === null) {
            $this->renderGatewayFailure(
                'instance.url_missing',
                "App instance [{$instance->name}] has no URL to profile.",
            );

            return null;
        }

        $path = $this->trimmedOption('path');

        return $path === null ? $base : rtrim($base, '/').'/'.ltrim($path, '/');
    }
}
