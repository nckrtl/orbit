<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Nodes\AppDevSetupScriptResponse;

/** @mago-expect lint:halstead The complete script response boundary stays visible in one test group. */
describe(AppDevSetupScriptResponse::class, function (): void {
    it('accepts the exact LF-separated Gateway setup summary', function (): void {
        $summary = implode("\n", [
            'Orbit will make these approved local macOS app-dev changes:',
            '- Install Homebrew in its supported default prefix when it is absent.',
            '- Enable Remote Login when it is disabled.',
            '- Install the Orbit PF redirect for this WireGuard address.',
            '- Install the root dnsmasq service on 127.0.0.1:53.',
            '- Install the local resolver for this node TLD.',
            '- Install user-owned Orbit files and LaunchAgents.',
        ]);

        $response = AppDevSetupScriptResponse::fromGatewayData([
            'role' => 'app-dev',
            'summary' => $summary,
            'script' => "#!/bin/bash\n",
        ], app_dev_script_response_request_id());

        expect($response->summary)->toBe($summary);
    });

    it('preserves empty and valid UTF-8 summaries', function (string $summary): void {
        $response = AppDevSetupScriptResponse::fromGatewayData([
            'role' => 'app-dev',
            'summary' => $summary,
            'script' => "#!/bin/bash\n",
        ], app_dev_script_response_request_id());

        expect($response->summary)->toBe($summary);
    })->with([
        'empty' => [''],
        'multibyte' => ['Installeer de goedgekeurde benodigdheden: café.'],
    ]);

    it('exposes the bounded script only through its explicit accessor', function (): void {
        expect(class_exists(AppDevSetupScriptResponse::class))->toBeTrue();

        $script = "#!/bin/bash\nset -euo pipefail\n";
        $response = AppDevSetupScriptResponse::fromGatewayData([
            'role' => 'app-dev',
            'summary' => 'Install the protected app-dev prerequisites.',
            'script' => $script,
        ], app_dev_script_response_request_id());

        expect($response->role)
            ->toBe('app-dev')
            ->and($response->summary)
            ->toBe('Install the protected app-dev prerequisites.')
            ->and($response->requestId)
            ->toBe(app_dev_script_response_request_id())
            ->and($response->script())
            ->toBe($script)
            ->and(method_exists($response, 'toArray'))
            ->toBeFalse()
            ->and(print_r($response, return: true))
            ->toBe(
                "Orbit\\Sdk\\Responses\\Nodes\\AppDevSetupScriptResponse Object\n(\n    [type] => Orbit\\Sdk\\Responses\\Nodes\\AppDevSetupScriptResponse\n)\n",
            );
    });

    it('denies script response serialization without disclosure', function (): void {
        $scriptCredential = 'script-serialization-credential';
        $response = AppDevSetupScriptResponse::fromGatewayData([
            'role' => 'app-dev',
            'summary' => 'Install the protected app-dev prerequisites.',
            'script' => "#!/bin/bash\necho {$scriptCredential}\n",
        ], app_dev_script_response_request_id());

        try {
            serialize($response);
            $this->fail('Expected script response serialization to fail closed.');
        } catch (LogicException $exception) {
            $frames = array_values(array_filter(
                $exception->getTrace(),
                static fn (array $frame): bool => (
                    is_string($frame['class'] ?? null) && str_starts_with($frame['class'], 'Orbit\\Sdk\\')
                ),
            ));
            $diagnostics = $exception->getMessage().(string) $exception.print_r($frames, return: true);

            expect($exception->getMessage())
                ->toBe('Orbit app-dev setup script responses cannot be serialized.')
                ->and($diagnostics)
                ->not->toContain($scriptCredential);
        }
    });

    it('rejects crafted script response unserialization', function (): void {
        $class = AppDevSetupScriptResponse::class;
        $serialized = sprintf('O:%d:"%s":0:{}', strlen($class), $class);

        expect(fn (): mixed => unserialize($serialized))
            ->toThrow(LogicException::class, 'Orbit app-dev setup script responses cannot be unserialized.');
    });

    it('fails malformed executable responses closed', function (array $data): void {
        expect(fn (): AppDevSetupScriptResponse => AppDevSetupScriptResponse::fromGatewayData(
            $data,
            app_dev_script_response_request_id(),
        ))
            ->toThrow(GatewayApiException::class, 'Gateway response contains an invalid app-dev setup script.');
    })->with([
        'missing role' => [[
            'summary' => 'Safe summary.',
            'script' => "#!/bin/bash\n",
        ]],
        'wrong role' => [[
            'role' => 'app-prod',
            'summary' => 'Safe summary.',
            'script' => "#!/bin/bash\n",
        ]],
        'missing summary' => [[
            'role' => 'app-dev',
            'script' => "#!/bin/bash\n",
        ]],
        'oversized summary' => [[
            'role' => 'app-dev',
            'summary' => str_repeat('s', times: 4097),
            'script' => "#!/bin/bash\n",
        ]],
        'carriage-return summary' => [[
            'role' => 'app-dev',
            'summary' => "Unsafe\rsummary",
            'script' => "#!/bin/bash\n",
        ]],
        'tab-bearing summary' => [[
            'role' => 'app-dev',
            'summary' => "Unsafe\tsummary",
            'script' => "#!/bin/bash\n",
        ]],
        'NUL-bearing summary' => [[
            'role' => 'app-dev',
            'summary' => "Unsafe\0summary",
            'script' => "#!/bin/bash\n",
        ]],
        'DEL-bearing summary' => [[
            'role' => 'app-dev',
            'summary' => "Unsafe\x7fsummary",
            'script' => "#!/bin/bash\n",
        ]],
        'C1-control-bearing summary' => [[
            'role' => 'app-dev',
            'summary' => "Unsafe\u{0085}summary",
            'script' => "#!/bin/bash\n",
        ]],
        'invalid UTF-8 summary' => [[
            'role' => 'app-dev',
            'summary' => "Unsafe\xc3\x28summary",
            'script' => "#!/bin/bash\n",
        ]],
        'missing script' => [[
            'role' => 'app-dev',
            'summary' => 'Safe summary.',
        ]],
        'empty script' => [[
            'role' => 'app-dev',
            'summary' => 'Safe summary.',
            'script' => '',
        ]],
        'oversized script' => [[
            'role' => 'app-dev',
            'summary' => 'Safe summary.',
            'script' => str_repeat('s', times: 262_145),
        ]],
    ]);
});

function app_dev_script_response_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
