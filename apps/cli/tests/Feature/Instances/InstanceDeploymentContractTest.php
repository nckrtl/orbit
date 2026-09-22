<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Deployments\ShowAppInstanceDeploymentRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Contract tests replay recorded Gateway responses and compare the complete command
 * output with tests/Expected. A fixture change that reaches a command fails here first.
 */
beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=160');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('instance deployment contract', function (): void {
    it('renders instance:deployment:list from the recorded response', function (): void {
        run_contract('instances/instance-deployment-list/default', 'instance:deployment:list', ['instance' => '1'], 'instances/instance-deployment-list/default.human.txt', 0);
        run_contract('instances/instance-deployment-list/default', 'instance:deployment:list', ['instance' => '1', '--json' => true], 'instances/instance-deployment-list/default.json', 0);
    });

    it('renders instance:deployment:show from the recorded response', function (): void {
        run_contract('instances/instance-deployment-show/default', 'instance:deployment:show', ['deployment' => '1'], 'instances/instance-deployment-show/default.human.txt', 0);
        run_contract('instances/instance-deployment-show/default', 'instance:deployment:show', ['deployment' => '1', '--json' => true], 'instances/instance-deployment-show/default.json', 0);
    });
});

describe('recorded deployment output bytes', function (): void {
    it('renders recorded bytes safely without changing the machine schema', function (string $bytes, string $quoted, string $decoded, bool $json): void {
        $mock = deployment_history_mock([
            ['type' => 'output', 'stream' => 'stdout', 'value_base64' => base64_encode($bytes)],
        ]);
        $tester = new CommandTester(app(Kernel::class)->all()['instance:deployment:show']);
        $status = $tester->execute(['deployment' => '1', '--json' => $json], [
            'interactive' => false, 'capture_stderr_separately' => true,
        ]);
        $output = $tester->getDisplay();

        expect($status)->toBe(0)
            ->and($tester->getErrorOutput())->toBe('')
            ->and($mock->getRecordedResponses())->toHaveCount(1)
            ->and($mock->getLastRequest())->toBeInstanceOf(ShowAppInstanceDeploymentRequest::class);

        foreach (["\x1b", "\x07", "\0", "\r", "\xff"] as $forbidden) {
            expect($output)->not->toContain($forbidden);
        }

        if ($json) {
            $expected = json_decode((string) file_get_contents(base_path('tests/Expected/instances/instance-deployment-show/default.json')), associative: true, flags: JSON_THROW_ON_ERROR);
            $expected['events'] = [[
                'type' => 'output', 'phase' => null, 'step_name' => null,
                'stream' => 'stdout', 'value' => $decoded,
            ]];
            expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe($expected);
        } else {
            expect($output)->toContain('stdout: '.$quoted."\n", 'Deployment: 1', '0198e15c-bf97-7c23-8f1f-61b8fe67a844');
        }
    })->with([
        'ordinary text' => ["Running composer install\n", '"Running composer install\\n"', "Running composer install\n"],
        'CSI' => ["first\x1b[2Jspoofed", '"first\\u001b[2Jspoofed"', "first\x1b[2Jspoofed"],
        'OSC' => ["\x1b]0;fake title\x07", '"\\u001b]0;fake title\\u0007"', "\x1b]0;fake title\x07"],
        'carriage return and line feed' => ["first\r\nsecond", '"first\\r\\nsecond"', "first\r\nsecond"],
        'NUL' => ["first\0second", '"first\\u0000second"', "first\0second"],
        'Unicode' => ['café 😀', '"caf\\u00e9 \\ud83d\\ude00"', 'café 😀'],
        'invalid UTF-8' => ["first\xff\n", '"first\\ufffd\\n"', "first\u{fffd}\n"],
    ])->with(['human' => false, 'JSON' => true]);

    it('keeps event order, stream labels, and the truncation marker', function (bool $json): void {
        $mock = deployment_history_mock([
            ['type' => 'output', 'stream' => 'stdout', 'value_base64' => base64_encode("first\x1b[2J\n")],
            ['type' => 'output', 'stream' => 'stderr', 'value_base64' => base64_encode("second\xff\r\n")],
            ['type' => 'output_truncated'],
        ]);
        $tester = new CommandTester(app(Kernel::class)->all()['instance:deployment:show']);
        expect($tester->execute(['deployment' => '1', '--json' => $json], [
            'interactive' => false, 'capture_stderr_separately' => true,
        ]))->toBe(0);
        $output = $tester->getDisplay();
        expect($tester->getErrorOutput())->toBe('')
            ->and($mock->getRecordedResponses())->toHaveCount(1);

        if ($json) {
            $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
            expect(array_column($payload['events'], 'type'))->toBe(['output', 'output', 'output_truncated'])
                ->and(array_column($payload['events'], 'stream'))->toBe(['stdout', 'stderr', null])
                ->and(array_column($payload['events'], 'value'))->toBe(["first\x1b[2J\n", "second\u{fffd}\r\n", null]);
        } else {
            expect($output)->toContain("stdout: \"first\\u001b[2J\\n\"\nstderr: \"second\\ufffd\\r\\n\"\n[output truncated]\n");
        }

        expect_output($output, 'instances/instance-deployment-show/bytes.'.($json ? 'json' : 'human.txt'));
    })->with(['human' => false, 'JSON' => true]);

    it('preserves empty history output', function (bool $json): void {
        deployment_history_mock([]);
        $tester = new CommandTester(app(Kernel::class)->all()['instance:deployment:show']);
        expect($tester->execute(['deployment' => '1', '--json' => $json], [
            'interactive' => false, 'capture_stderr_separately' => true,
        ]))->toBe(0);
        $output = $tester->getDisplay();
        expect($tester->getErrorOutput())->toBe('');

        if ($json) {
            expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR)['events'])->toBe([]);
        } else {
            expect($output)->toContain('No recorded phases.', 'No recorded log output.');
        }
    })->with(['human' => false, 'JSON' => true]);
});

/** @param list<array<string, mixed>> $events */
function deployment_history_mock(array $events): MockClient
{
    $body = json_decode(gateway_fixture('instances/instance-deployment-show/default')['body'], associative: true, flags: JSON_THROW_ON_ERROR);
    $body['data']['events'] = $events;

    return MockClient::global([ShowAppInstanceDeploymentRequest::class => MockResponse::make($body)]);
}
