<?php

declare(strict_types=1);

use App\Commands\ProjectDocuments\CreateProjectDocumentCommand;
use App\Commands\ProjectDocuments\DownloadProjectDocumentCommand;
use App\Commands\ProjectDocuments\ProjectDocumentCommand;
use App\Commands\ProjectDocuments\UpdateProjectDocumentCommand;
use App\Commands\ProjectDocuments\UploadProjectDocumentCommand;
use App\Commands\ProjectDocuments\WriteProjectDocumentCommand;
use App\Console\Kernel;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Support\Console\CommandPrompts;
use App\Support\Console\ConsoleMode;
use App\Support\Console\PromptAborted;
use App\Support\Console\PromptContext;
use App\Support\DocumentFileWriter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;
use Orbit\Sdk\Requests\ProjectDocuments\ShowProjectDocumentRequest;
use Orbit\Sdk\Requests\ProjectDocuments\ShowProjectDocumentStorageRequest;
use Orbit\Sdk\Requests\ProjectDocuments\UpdateProjectDocumentStorageRequest;
use Orbit\Sdk\Requests\Projects\ListProjectsRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->documentHome = sys_get_temp_dir().'/orbit-document-cli-'.Str::uuid();
    config(['orbit.home' => $this->documentHome]);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://gateway.test', '/tmp/root.pem'));
});
afterEach(function (): void {
    MockClient::destroyGlobal();
    File::deleteDirectory($this->documentHome);
});
function cli_document_fixture(string $operation): array
{
    $name = match ($operation) {
        'create' => 'project-documents/create/default',
        'list' => 'project-documents/list/default',
        'search' => 'project-documents/search/default',
        'show' => 'project-documents/show/default',
        'read' => 'project-documents/read/default',
        'download' => 'project-documents/download/default',
        'update' => 'project-documents/update/default',
        'write' => 'project-documents/write/default',
        'versions' => 'project-documents/versions/default',
        'restore-version' => 'project-documents/restore-version/default',
        'archive' => 'project-documents/archive/default',
        'restore' => 'project-documents/restore/default',
        'remove' => 'project-documents/remove/default',
        'conflict' => 'project-documents/conflict/default',
        'storage-show' => 'project-documents/storage-show/default',
    };

    return json_decode(file_get_contents(base_path('../../packages/php-sdk/fixtures/'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR);
}
function cli_document_mock(string $operation): MockClient
{
    $fixture = cli_document_fixture($operation);
    $show = cli_document_fixture('show');

    return MockClient::global([
        $fixture['request'] => MockResponse::make($fixture['body'], $fixture['status'], ['X-Orbit-Request-Id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']),
        ...($operation === 'show' ? [] : [ShowProjectDocumentRequest::class => MockResponse::make($show['body'])]),
    ]);
}

describe('Project Documents CLI', function (): void {
    it('shows redacted storage status and sends credential files without exposing them', function (): void {
        $fixture = cli_document_fixture('storage-show');
        $mock = MockClient::global([
            ShowProjectDocumentStorageRequest::class => MockResponse::make($fixture['body']),
            UpdateProjectDocumentStorageRequest::class => MockResponse::make($fixture['body']),
        ]);
        expect(Artisan::call('project:document-storage:show', ['--json' => true]))->toBe(0);
        expect(json_decode(Artisan::output(), true))->toBe($fixture['body']);
        $access = $this->documentHome.'/access';
        $secret = $this->documentHome.'/secret';
        file_put_contents($access, "write-only-access\n");
        file_put_contents($secret, "write-only-secret\n");
        expect(Artisan::call('project:document-storage:update', ['--access-key-id-file' => $access, '--secret-access-key-file' => $secret, '--json' => true]))->toBe(0);
        expect($mock->getLastRequest()?->body()->all())->toBe(['access_key_id' => 'write-only-access', 'secret_access_key' => 'write-only-secret']);
        expect(Artisan::output())->not->toContain('write-only-access', 'write-only-secret');
    });

    it('treats --version as a file version without changing the application version switch', function (): void {
        cli_document_mock('download');
        $output = new BufferedOutput;
        expect(app(Kernel::class)->handle(new StringInput('project:document:download 1 1 --version=1 --json'), $output))->toBe(0);
        expect(json_decode($output->fetch(), true)['data']['content_base64'])->toBe(base64_encode("first\n"));
        $output = new BufferedOutput;
        expect(app(Kernel::class)->handle(new StringInput('--version'), $output))->toBe(0);
        expect($output->fetch())->toContain('Orbit');
    });

    it('preserves each recorded API envelope in JSON mode', function (string $operation): void {
        cli_document_mock($operation);
        $arguments = match ($operation) {
            'create' => ['project' => '1', 'name' => 'note.txt', '--kind' => 'file', '--content' => "first\n"],
            'list' => ['project' => '1'],
            'search' => ['project' => '1', 'query' => 'note'],
            'show' => ['project' => '1', 'entry' => '1'],
            'read' => ['project' => '1', 'entry' => '1'],
            'download' => ['project' => '1', 'entry' => '1'],
            'update' => ['project' => '1', 'entry' => '1', '--expected-revision' => '1', '--name' => 'renamed.txt'],
            'write' => ['project' => '1', 'entry' => '1', '--expected-revision' => '2', '--content' => 'second'],
            'versions' => ['project' => '1', 'entry' => '1'],
            'restore-version' => ['project' => '1', 'entry' => '1', '--expected-revision' => '3', '--version' => '1'],
            'archive' => ['project' => '1', 'entry' => '1', '--expected-revision' => '4'],
            'restore' => ['project' => '1', 'entry' => '1', '--expected-revision' => '5'],
            'remove' => ['project' => '1', 'entry' => '1', '--expected-revision' => '6', '--yes' => true],
        };
        expect(Artisan::call('project:document:'.$operation, [...$arguments, '--json' => true]))->toBe(0);
        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe(cli_document_fixture($operation)['body']);
    })->with(['create', 'list', 'search', 'show', 'read', 'download', 'update', 'write', 'versions', 'restore-version', 'archive', 'restore', 'remove']);

    it('uploads bounded local bytes and preserves the conflict without retrying', function (): void {
        cli_document_mock('write');
        $path = $this->documentHome.'/input';
        file_put_contents($path, "\0attachment\n");
        expect(Artisan::call('project:document:upload', ['project' => '1', 'entry' => '1', '--expected-revision' => '2', '--from' => $path, '--json' => true]))->toBe(0);
        expect(MockClient::getGlobal()?->getLastRequest()?->body()->all())->toBe(['expected_revision' => 2, 'content_base64' => base64_encode("\0attachment\n")]);
        MockClient::destroyGlobal();
        $mock = cli_document_mock('conflict');
        expect(Artisan::call('project:document:write', ['project' => '1', 'entry' => '1', '--expected-revision' => '2', '--content' => 'draft', '--json' => true]))->toBe(1);
        expect(json_decode(Artisan::output(), true)['error']['details'])->toBe(['entry_id' => 1, 'current_revision' => 3]);
        $mock->assertSentCount(1);
    });

    it('renders recorded metadata and lists through the shared human renderer', function (string $operation, array $arguments): void {
        $originalColumns = getenv('COLUMNS');
        putenv('COLUMNS=80');

        try {
            cli_document_mock($operation);
            expect(Artisan::call('project:document:'.$operation, $arguments))->toBe(0);
            expect_output(Artisan::output(), 'project-documents/project-document-'.$operation.'/default.human.txt');
        } finally {
            putenv($originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$originalColumns);
        }
    })->with([
        ['show', ['project' => '1', 'entry' => '1']],
        ['list', ['project' => '1']],
        ['versions', ['project' => '1', 'entry' => '1']],
    ]);

    it('preserves a discovery API failure as one JSON envelope without submitting a mutation', function (): void {
        $fixture = json_decode(file_get_contents(base_path('../../packages/php-sdk/fixtures/tools/tool-scan/access-required.json')), true, flags: JSON_THROW_ON_ERROR);
        $mock = MockClient::global([
            ListProjectsRequest::class => MockResponse::make($fixture['body'], 403, ['X-Orbit-Request-Id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']),
        ]);
        expect(Artisan::call('project:document:create', ['project' => 'documents', 'name' => 'folder', '--kind' => 'folder', '--json' => true]))->toBe(1);
        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('node_access.required');
        $mock->assertSentCount(1);
    });

    it('writes exact inline text without a trailing newline or body diagnostics on stdout', function (): void {
        cli_document_mock('read');
        expect(Artisan::call('project:document:read', ['project' => '1', 'entry' => '1']))->toBe(0);
        expect(Artisan::output())->toBe("first\n");
    });

    it('validates download bytes and never overwrites an existing file', function (): void {
        cli_document_mock('download');
        $path = $this->documentHome.'/download';
        expect(Artisan::call('project:document:download', ['project' => '1', 'entry' => '1', '--output' => $path]))->toBe(0);
        expect(file_get_contents($path))->toBe("first\n");
        MockClient::destroyGlobal();
        $mock = MockClient::global();
        expect(Artisan::call('project:document:download', ['project' => '1', 'entry' => '1', '--output' => $path]))->toBe(2);
        $mock->assertNothingSent();
        expect(file_get_contents($path))->toBe("first\n");
    });

    it('returns a sanitized local I/O failure and removes private temporary files after filesystem faults', function (string $fault): void {
        $mock = cli_document_mock('download');
        $files = Mockery::mock(Filesystem::class)->makePartial();
        $path = $this->documentHome.'/download';
        match ($fault) {
            'directory' => $files->shouldReceive('isWritable')->once()->andReturn(false),
            'chmod false' => $files->shouldReceive('chmod')->once()->andReturn(false),
            'chmod warning' => $files->shouldReceive('chmod')->once()->andThrow(new ErrorException('chmod('.$path.'): permission denied')),
            'write false' => $files->shouldReceive('put')->once()->andReturn(false),
            'write warning' => $files->shouldReceive('put')->once()->andThrow(new ErrorException('file_put_contents('.$path.'): no space left')),
            'partial write' => $files->shouldReceive('put')->once()->andReturnUsing(static function (string $temp, string $bytes): int {
                file_put_contents($temp, substr($bytes, 0, 1));

                return 1;
            }),
        };
        app()->instance(DocumentFileWriter::class, new DocumentFileWriter($files));
        [$status, $display, $failure] = cli_document_prompt_run('download', ['project' => '1', 'entry' => '1', '--output' => $path], []);
        expect($status)->toBe(1)->and($failure['code'])->toBe('project_documents.local_io_failed');
        expect($failure['message'])->not->toContain($path, 'permission denied', 'no space left');
        expect(file_exists($path))->toBeFalse()->and(glob($this->documentHome.'/.orbit-document-*'))->toBe([]);
        $mock->assertSentCount(1);
    })->with(['directory', 'chmod false', 'chmod warning', 'write false', 'write warning', 'partial write']);

    it('sanitizes temporary allocation warnings and refuses a fallback temporary directory', function (): void {
        $mock = cli_document_mock('download');
        $directory = $this->documentHome.'/output-directory';
        mkdir($directory, 0700);
        $path = $directory.'/download';
        $files = Mockery::mock(Filesystem::class)->makePartial();
        $files->shouldReceive('isWritable')->once()->with($directory)->andReturnUsing(static fn (): bool => rmdir($directory));
        app()->instance(DocumentFileWriter::class, new DocumentFileWriter($files));
        [$status, , $failure] = cli_document_prompt_run('download', ['project' => '1', 'entry' => '1', '--output' => $path], []);
        expect($status)->toBe(1)->and($failure)->toBe(['code' => 'project_documents.local_io_failed', 'message' => 'Cannot create the download file.']);
        expect(file_exists($path))->toBeFalse();
        $mock->assertSentCount(1);
    });

    it('keeps a racing destination unchanged and removes its temporary download', function (): void {
        cli_document_mock('download');
        $path = $this->documentHome.'/racing-download';
        $files = Mockery::mock(Filesystem::class)->makePartial();
        $files->shouldReceive('put')->once()->andReturnUsing(static function (string $temp, string $bytes) use ($path): int {
            file_put_contents($path, 'racing creator');

            return file_put_contents($temp, $bytes);
        });
        app()->instance(DocumentFileWriter::class, new DocumentFileWriter($files));
        [$status, , $failure] = cli_document_prompt_run('download', ['project' => '1', 'entry' => '1', '--output' => $path], []);
        expect($status)->toBe(1)->and($failure['code'])->toBe('project_documents.local_io_failed');
        expect(file_get_contents($path))->toBe('racing creator')->and(glob($this->documentHome.'/.orbit-document-*'))->toBe([]);
    });

    it('corrects repeated invalid prompted names before one mutation', function (string $operation): void {
        $mock = cli_document_mock($operation);
        $keys = [];
        foreach (['../bad', '.', ' trailing ', str_repeat('x', 256)] as $invalid) {
            array_push($keys, $invalid, Key::ENTER, ...array_fill(0, strlen($invalid), Key::BACKSPACE));
        }
        array_push($keys, 'corrected.txt', Key::ENTER);
        $arguments = $operation === 'create' ? ['project' => '1', '--kind' => 'folder'] : ['project' => '1', 'entry' => '1', '--expected-revision' => '1'];
        [$status, $display] = cli_document_prompt_run($operation, $arguments, $keys);
        expect($status)->toBe(0)->and($display)->toContain('without paths, controls, or surrounding whitespace.');
        expect($mock->getLastRequest()?->body()->all()['name'])->toBe('corrected.txt');
        $mock->assertSentCount(1);
    })->with(['create', 'update']);

    it('corrects unreadable, oversized and noneditable prompted local files before one write', function (string $operation): void {
        $mock = cli_document_mock('write');
        $oversized = $this->documentHome.'/oversized';
        $binary = $this->documentHome.'/binary';
        $valid = $this->documentHome.'/valid';
        file_put_contents($oversized, str_repeat('x', 10485761));
        file_put_contents($binary, "\0binary");
        file_put_contents($valid, 'corrected');
        $keys = [];
        $paths = [$this->documentHome.'/missing', $oversized, ...($operation === 'write' ? [$binary] : [])];
        foreach ($paths as $invalid) {
            array_push($keys, $invalid, Key::ENTER, ...array_fill(0, strlen($invalid), Key::BACKSPACE));
        }
        array_push($keys, $valid, Key::ENTER);
        [$status, $display] = cli_document_prompt_run($operation, ['project' => '1', 'entry' => '1', '--expected-revision' => '2'], $keys);
        expect($status)->toBe(0)->and($display)->toContain('Cannot read the local input file.', 'Document content is too large.');
        $body = $mock->getLastRequest()?->body()->all();
        expect($body[$operation === 'write' ? 'content_text' : 'content_base64'])->toBe($operation === 'write' ? 'corrected' : base64_encode('corrected'));
        $mock->assertSentCount(1);
    })->with(['write', 'upload']);

    it('cancels invalid prompted input without writing', function (string $operation, string $invalid, string $cancel): void {
        $mock = MockClient::global();
        $arguments = $operation === 'create' ? ['project' => '1', '--kind' => 'folder'] : ['project' => '1', 'entry' => '1', '--expected-revision' => '1'];
        [$status] = cli_document_prompt_run($operation, $arguments, [$invalid, Key::ENTER, $cancel]);
        expect($status)->toBe(2);
        $mock->assertNothingSent();
    })->with([['create', '../bad'], ['update', '../bad'], ['upload', '/missing/document-input'], ['write', '/missing/document-input']])->with([Key::CTRL_C, Key::CTRL_D]);

    it('retains the corrected prompted download path instead of asking again', function (): void {
        $mock = cli_document_mock('download');
        $existing = $this->documentHome.'/existing';
        $path = $this->documentHome.'/corrected-download';
        file_put_contents($existing, 'keep');
        [$status] = cli_document_prompt_run('download', ['project' => '1', 'entry' => '1'], [$existing, Key::ENTER, ...array_fill(0, strlen($existing), Key::BACKSPACE), $path, Key::ENTER]);
        expect($status)->toBe(0)->and(file_get_contents($path))->toBe("first\n")->and(file_get_contents($existing))->toBe('keep');
        expect(fileperms($path) & 0777)->toBe(0600)->and(glob($this->documentHome.'/.orbit-document-*'))->toBe([]);
        $mock->assertSentCount(1);
    });

    it('refuses explicit invalid names without prompting or mutation', function (string $operation, string $invalid): void {
        $mock = MockClient::global();
        $arguments = $operation === 'create' ? ['project' => '1', 'name' => $invalid, '--kind' => 'folder'] : ['project' => '1', 'entry' => '1', '--expected-revision' => '1', '--name' => $invalid];
        expect(Artisan::call('project:document:'.$operation, [...$arguments, '--json' => true]))->toBe(2);
        expect(json_decode(Artisan::output(), true)['error']['code'])->toBe('input.invalid');
        $mock->assertNothingSent();
    })->with(['create', 'update'])->with(['../bad', 'bad\\path', '.', '..', ' leading', 'trailing ', "bad\0name", str_repeat('é', 128)]);

    it('refuses missing revision, missing removal consent and mutually exclusive sources before mutation', function (string $command, array $arguments): void {
        $mock = MockClient::global();
        expect(Artisan::call($command, [...$arguments, '--json' => true]))->toBe(2);
        expect(json_decode(Artisan::output(), true)['error']['code'])->toBe('input.invalid');
        $mock->assertNothingSent();
    })->with([
        ['project:document:archive', ['project' => '1', 'entry' => '1']],
        ['project:document:remove', ['project' => '1', 'entry' => '1', '--expected-revision' => '1']],
        ['project:document:write', ['project' => '1', 'entry' => '1', '--expected-revision' => '1', '--content' => 'text', '--from' => 'file']],
        ['project:document:download', ['project' => '1', 'entry' => '1', '--output' => 'file']],
    ]);
});

/**
 * @param  array<string, string>  $arguments
 * @param  list<string>  $keys
 * @return array{int, string, array{code?: string, message?: string}}
 */
function cli_document_prompt_run(string $operation, array $arguments, array $keys): array
{
    $command = new DocumentPromptFixtureCommand(new DocumentPromptFixtureTerminal($keys), $operation);
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    return PromptContext::preserve(function () use ($command, $tester, $arguments): array {
        Prompt::fallbackWhen(false);

        return [$tester->execute($arguments), $tester->getDisplay(), $command->failure];
    });
}

final class DocumentPromptFixtureCommand extends ProjectDocumentCommand
{
    protected $signature = 'document:prompt-fixture';

    /** @var array{code?: string, message?: string} */
    public array $failure = [];

    public function __construct(private readonly Terminal $terminal, string $operation)
    {
        parent::__construct();
        $command = match ($operation) {
            'create' => new CreateProjectDocumentCommand,
            'update' => new UpdateProjectDocumentCommand,
            'write' => new WriteProjectDocumentCommand,
            'upload' => new UploadProjectDocumentCommand,
            'download' => new DownloadProjectDocumentCommand,
        };
        $this->verb = $operation;
        $this->setDefinition($command->getNativeDefinition());
    }

    protected function consoleMode(?OutputInterface $output = null): ConsoleMode
    {
        return new ConsoleMode(false, true, false, false, 100);
    }

    protected function commandPrompts(): CommandPrompts
    {
        return new CommandPrompts($this->consoleMode(), $this->output, $this->terminal);
    }

    protected function renderGatewayFailure(string $code, string $message, ?string $requestId = null, ?string $humanMessage = null, array $details = []): int
    {
        $this->failure = ['code' => $code, 'message' => $message];

        return parent::renderGatewayFailure($code, $message, $requestId, $humanMessage, $details);
    }
}

final class DocumentPromptFixtureTerminal extends Terminal
{
    /** @param list<string> $keys */
    public function __construct(private array $keys)
    {
        parent::__construct();
    }

    public function read(): string
    {
        return array_shift($this->keys) ?? throw new PromptAborted('Input ended.');
    }

    public function setTty(string $mode): void {}

    public function restoreTty(): void {}

    public function cols(): int
    {
        return 100;
    }

    public function lines(): int
    {
        return 40;
    }
}
