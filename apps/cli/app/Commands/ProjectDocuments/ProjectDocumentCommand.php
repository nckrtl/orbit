<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\PromptAborted;
use App\Support\DocumentFileWriter;
use Closure;
use ErrorException;
use Illuminate\Console\OutputStyle;
use InvalidArgumentException;
use Laravel\Prompts\SelectPrompt;
use Laravel\Prompts\TextPrompt;
use Normalizer;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\ProjectDocuments\ArchiveProjectDocumentRequest;
use Orbit\Sdk\Requests\ProjectDocuments\CreateProjectDocumentRequest;
use Orbit\Sdk\Requests\ProjectDocuments\DestroyProjectDocumentRequest;
use Orbit\Sdk\Requests\ProjectDocuments\DownloadProjectDocumentRequest;
use Orbit\Sdk\Requests\ProjectDocuments\ListProjectDocumentsRequest;
use Orbit\Sdk\Requests\ProjectDocuments\ListProjectDocumentVersionsRequest;
use Orbit\Sdk\Requests\ProjectDocuments\ReadProjectDocumentRequest;
use Orbit\Sdk\Requests\ProjectDocuments\RestoreProjectDocumentRequest;
use Orbit\Sdk\Requests\ProjectDocuments\RestoreProjectDocumentVersionRequest;
use Orbit\Sdk\Requests\ProjectDocuments\SearchProjectDocumentsRequest;
use Orbit\Sdk\Requests\ProjectDocuments\ShowProjectDocumentRequest;
use Orbit\Sdk\Requests\ProjectDocuments\UpdateProjectDocumentRequest;
use Orbit\Sdk\Requests\ProjectDocuments\WriteProjectDocumentRequest;
use Orbit\Sdk\Requests\Projects\ListProjectsRequest;
use Orbit\Sdk\Requests\Projects\ShowProjectRequest;
use Orbit\Sdk\Responses\ProjectDocuments\DownloadProjectDocumentResponse;
use Orbit\Sdk\Responses\ProjectDocuments\ProjectDocumentResponse;
use Orbit\Sdk\Responses\ProjectDocuments\ProjectDocumentsResponse;
use Orbit\Sdk\Responses\ProjectDocuments\ProjectDocumentVersionsResponse;
use Orbit\Sdk\Responses\ProjectDocuments\ReadProjectDocumentResponse;
use Orbit\Sdk\Responses\ProjectDocuments\RemovedProjectDocumentResponse;
use Orbit\Sdk\Responses\Projects\ProjectResponse;
use Orbit\Sdk\Responses\Projects\ProjectsResponse;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use UnexpectedValueException;

abstract class ProjectDocumentCommand extends GatewayCommand
{
    protected string $verb;

    private ?string $downloadOutput = null;

    public function mergeApplicationDefinition(bool $mergeArgs = true): void
    {
        $application = $this->getApplication();
        if ($application === null || ! $this->getNativeDefinition()->hasOption('version')) {
            parent::mergeApplicationDefinition($mergeArgs);

            return;
        }
        $definition = $application->getDefinition();
        $scoped = clone $definition;
        $options = $scoped->getOptions();
        unset($options['version']);
        $scoped->setOptions($options);
        $application->setDefinition($scoped);
        try {
            parent::mergeApplicationDefinition($mergeArgs);
        } finally {
            $application->setDefinition($definition);
        }
    }

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors, DocumentFileWriter $downloads): int
    {
        $resultOutput = $this->output;
        $raw = in_array($this->verb, ['read', 'download'], true) && $this->documentOption('json') !== true;
        if ($raw) {
            $errors = $resultOutput instanceof ConsoleOutputInterface ? $resultOutput->getErrorOutput() : new StreamOutput(STDERR);
            $this->output = new OutputStyle($this->input, $errors);
        }
        try {
            $this->downloadOutput = null;
            $this->validateLocal();
            $connector = $this->gatewayConnector($repository, $connectors);
            if ($connector === null) {
                return 1;
            }
            [$project, $projectName] = $this->project($connector);
            $entry = in_array($this->verb, ['list', 'search', 'create'], true) ? null : $this->entry($connector, $project);
            $request = $this->request($connector, $project, $entry);
            if ($this->verb === 'remove') {
                if ($this->consoleMode()->mayPrompt && ctype_digit($projectName)) {
                    $namedProject = $this->send($connector, new ShowProjectRequest($project), ProjectResponse::class);
                    if ($namedProject === null) {
                        return 1;
                    }
                    $projectName = $namedProject->slug;
                }
                $subject = $this->send($connector, new ShowProjectDocumentRequest($project, $entry ?? 0), ProjectDocumentResponse::class);
                if ($subject === null) {
                    return 1;
                }
                if (! $this->confirmAction('Permanently remove '.$projectName.'/'.$subject->path.($this->documentOption('recursive') ? ' and all descendants' : '').'? This cannot be undone.', 'Removal cancelled.')) {
                    return 2;
                }
            }
            $class = match ($this->verb) {
                'list', 'search' => ProjectDocumentsResponse::class,
                'versions' => ProjectDocumentVersionsResponse::class,
                'read' => ReadProjectDocumentResponse::class,
                'download' => DownloadProjectDocumentResponse::class,
                'remove' => RemovedProjectDocumentResponse::class,
                default => ProjectDocumentResponse::class,
            };
            $response = $this->sendWithProgress($connector, $request, $class, ['Project Documents', 'Sending request', 'Request completed'], dismiss: true);
            if ($response === null) {
                return 1;
            }
            if ($this->documentOption('json') === true) {
                $this->writeJson($response->toArray());

                return 0;
            }
            if ($response instanceof ReadProjectDocumentResponse) {
                ConsoleWriter::write($resultOutput, $response->contentText);

                return 0;
            }
            if ($response instanceof DownloadProjectDocumentResponse) {
                $bytes = $response->decodedBytes();
                $output = $this->downloadOutput ?? throw new RuntimeException('Download output was not validated.');
                if ($output === '-') {
                    if (stream_isatty(STDOUT) && (str_contains($bytes, "\0") || ! mb_check_encoding($bytes, 'UTF-8'))) {
                        throw new InvalidArgumentException('Binary stdout requires a pipe or output file.');
                    }
                    ConsoleWriter::write($resultOutput, $bytes);
                } else {
                    $downloads->write($output, $bytes);
                }

                return 0;
            }
            $this->renderResult($response);

            return 0;
        } catch (DocumentRequestFailed) {
            return 1;
        } catch (InvalidArgumentException|PromptAborted $exception) {
            $this->renderGatewayFailure('input.invalid', $exception->getMessage());

            return 2;
        } catch (UnexpectedValueException|RuntimeException $exception) {
            $this->renderGatewayFailure('project_documents.local_io_failed', $exception->getMessage());

            return 1;
        } finally {
            $this->output = $resultOutput;
        }
    }

    private function documentOption(string $name): mixed
    {
        return $this->getNativeDefinition()->hasOption($name) ? $this->input->getOption($name) : null;
    }

    private function documentArgument(string $name): mixed
    {
        return $this->getDefinition()->hasArgument($name) ? $this->input->getArgument($name) : null;
    }

    private function validateLocal(): void
    {
        if (in_array($this->verb, ['create', 'write'], true) && $this->documentOption('from') !== null && $this->documentOption('content') !== null) {
            throw new InvalidArgumentException('Use only one of --from and --content.');
        }
        if ($this->verb === 'download') {
            if ($this->documentOption('json') === true && $this->documentOption('output') !== null) {
                throw new InvalidArgumentException('JSON download forbids --output.');
            }
            if ($this->documentOption('json') !== true) {
                $this->downloadOutput = $this->outputPath();
            }
        }
        if (! $this->consoleMode()->mayPrompt) {
            if ($this->documentArgument('project') === null) {
                throw new InvalidArgumentException('Project ID or slug is required.');
            }
            if ($this->getDefinition()->hasArgument('entry') && $this->documentArgument('entry') === null) {
                throw new InvalidArgumentException('Entry ID is required.');
            }
            if ($this->getNativeDefinition()->hasOption('expected-revision')) {
                $this->positiveOption('expected-revision', true);
            }
            if ($this->verb === 'remove' && $this->documentOption('yes') !== true) {
                throw new InvalidArgumentException('Supply --yes for permanent removal.');
            }
        }
        foreach (['expected-revision', 'version', 'limit'] as $option) {
            if ($this->getNativeDefinition()->hasOption($option) && $this->documentOption($option) !== null) {
                $this->positiveOption($option);
            }
        }
    }

    /** @return array{int, string} */
    private function project(GatewayConnector $connector): array
    {
        $reference = $this->documentArgument('project');
        if (is_string($reference) && ctype_digit($reference)) {
            return [$this->id($reference, 'Project'), $reference];
        }
        $projects = $this->send($connector, new ListProjectsRequest, ProjectsResponse::class);
        if ($projects === null) {
            throw new DocumentRequestFailed;
        }
        if ($reference !== null) {
            foreach ($projects->projects as $project) {
                if ($project->slug === $reference) {
                    return [$project->id, $project->slug];
                }
            }
            throw new InvalidArgumentException('Project was not found.');
        }
        if (! $this->consoleMode()->mayPrompt) {
            throw new InvalidArgumentException('Project is required.');
        }
        $rows = [];
        foreach ($projects->projects as $project) {
            $rows[$project->id] = [(string) $project->id, $project->slug, $project->name];
        }
        if ($rows === []) {
            throw new InvalidArgumentException('No authorized Projects.');
        }
        $id = (int) $this->commandPrompts()->selectEntity('Project', ['ID', 'SLUG', 'NAME'], $rows);

        return [$id, $rows[$id][1]];
    }

    private function entry(GatewayConnector $connector, int $project): int
    {
        $entry = $this->documentArgument('entry');
        if ($entry !== null) {
            return $this->id($entry, 'Entry');
        }
        if (! $this->consoleMode()->mayPrompt) {
            throw new InvalidArgumentException('Entry is required.');
        }
        $rows = [];
        $cursor = null;
        do {
            $page = $this->send($connector, new SearchProjectDocumentsRequest($project, '/', state: 'all', cursor: $cursor), ProjectDocumentsResponse::class);
            if ($page === null) {
                throw new DocumentRequestFailed;
            }
            foreach ($page->entries as $item) {
                $rows[$item->id] = [(string) $item->id, $item->kind, $item->path, (string) $item->revision];
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $cursor = null;
        do {
            $page = $this->send($connector, new ListProjectDocumentsRequest($project, state: 'all', cursor: $cursor), ProjectDocumentsResponse::class);
            if ($page === null) {
                throw new DocumentRequestFailed;
            }
            foreach ($page->entries as $item) {
                $rows[$item->id] = [(string) $item->id, $item->kind, $item->path, (string) $item->revision];
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if ($rows === []) {
            throw new InvalidArgumentException('No authorized Documents.');
        }

        return (int) $this->commandPrompts()->selectEntity('Document', ['ID', 'KIND', 'PATH', 'REVISION'], $rows);
    }

    private function request(GatewayConnector $connector, int $project, ?int $entry): GatewayRequest
    {
        $revision = null;
        if ($this->getNativeDefinition()->hasOption('expected-revision')) {
            $revision = $this->positiveOption('expected-revision');
            if ($revision === null) {
                $current = $this->send($connector, new ShowProjectDocumentRequest($project, $entry ?? 0), ProjectDocumentResponse::class);
                if ($current === null) {
                    throw new DocumentRequestFailed;
                }
                $revision = $current->revision;
                $this->writeHumanMessage('Expected revision: '.$revision);
            }
        }
        $entry ??= 0;
        $revision ??= 0;
        $media = $this->getNativeDefinition()->hasOption('media-type') ? $this->stringOption('media-type') : null;

        return match ($this->verb) {
            'list' => new ListProjectDocumentsRequest($project, $this->parent(), $this->stringOption('state'), $this->stringOption('kind'), $this->stringOption('cursor'), $this->positiveOption('limit')),
            'search' => new SearchProjectDocumentsRequest($project, $this->requiredArgument('query', 'Search query'), $this->stringOption('state'), $this->stringOption('kind'), $this->stringOption('cursor'), $this->positiveOption('limit')),
            'show' => new ShowProjectDocumentRequest($project, $entry),
            'create' => $this->createRequest($project),
            'update' => $this->updateRequest($project, $entry, $revision),
            'read' => new ReadProjectDocumentRequest($project, $entry, $this->positiveOption('version')),
            'download' => new DownloadProjectDocumentRequest($project, $entry, $this->positiveOption('version')),
            'versions' => new ListProjectDocumentVersionsRequest($project, $entry, $this->stringOption('cursor'), $this->positiveOption('limit')),
            'upload' => new WriteProjectDocumentRequest($project, $entry, $revision, contentBase64: WriteProjectDocumentRequest::encodeBytes($this->source(false)), mediaType: $media),
            'write' => new WriteProjectDocumentRequest($project, $entry, $revision, contentText: $this->source(true), mediaType: $media),
            'restore-version' => new RestoreProjectDocumentVersionRequest($project, $entry, $revision, $this->version($connector, $project, $entry)),
            'archive' => new ArchiveProjectDocumentRequest($project, $entry, $revision),
            'restore' => new RestoreProjectDocumentRequest($project, $entry, $revision),
            'remove' => new DestroyProjectDocumentRequest($project, $entry, $revision, $this->documentOption('recursive') === true),
            default => throw new InvalidArgumentException('Unknown Document operation.'),
        };
    }

    private function createRequest(int $project): CreateProjectDocumentRequest
    {
        $kind = $this->stringOption('kind');
        if ($kind === null && $this->consoleMode()->mayPrompt) {
            $kind = $this->commandPrompts()->run(fn (): SelectPrompt => new SelectPrompt('Kind', ['folder', 'file']));
        }
        if (! in_array($kind, ['folder', 'file'], true)) {
            throw new InvalidArgumentException('Supply --kind=folder or --kind=file.');
        }
        $name = $this->documentName($this->documentArgument('name'), 'Document name');
        if ($kind === 'folder') {
            if ($this->documentOption('from') !== null || $this->documentOption('content') !== null || $this->documentOption('media-type') !== null) {
                throw new InvalidArgumentException('Folders cannot contain body fields.');
            }

            return new CreateProjectDocumentRequest($project, $kind, $name, $this->parent());
        }
        $text = $this->documentOption('content') !== null;
        $bytes = $this->source($text);

        return new CreateProjectDocumentRequest($project, $kind, $name, $this->parent(), $text ? $bytes : null, $text ? null : CreateProjectDocumentRequest::encodeBytes($bytes), $this->stringOption('media-type'));
    }

    private function updateRequest(int $project, int $entry, int $revision): UpdateProjectDocumentRequest
    {
        $name = $this->stringOption('name');
        $parent = $this->documentOption('parent');
        if ($name !== null || $parent === null) {
            $name = $this->documentName($name, 'New name');
        }

        return new UpdateProjectDocumentRequest($project, $entry, $revision, $name, $this->parent(), $parent !== null);
    }

    private function version(GatewayConnector $connector, int $project, int $entry): int
    {
        $version = $this->positiveOption('version');
        if ($version !== null) {
            return $version;
        }
        if (! $this->consoleMode()->mayPrompt) {
            throw new InvalidArgumentException('Supply --version.');
        }
        $rows = [];
        $cursor = null;
        do {
            $page = $this->send($connector, new ListProjectDocumentVersionsRequest($project, $entry, $cursor), ProjectDocumentVersionsResponse::class);
            if ($page === null) {
                throw new DocumentRequestFailed;
            }
            foreach ($page->versions as $item) {
                $rows[$item->id] = [(string) $item->number, (string) $item->id, $item->mediaType, (string) $item->sizeBytes];
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if ($rows === []) {
            throw new InvalidArgumentException('No versions.');
        }

        return (int) $this->commandPrompts()->selectEntity('Version', ['NUMBER', 'ID', 'TYPE', 'SIZE'], $rows);
    }

    private function parent(): ?int
    {
        $parent = $this->documentOption('parent');

        return $parent === null || $parent === 'root' ? null : $this->id($parent, 'Parent');
    }

    private function positiveOption(string $name, bool $required = false): ?int
    {
        $value = $this->documentOption($name);
        if ($value === null && ! $required) {
            return null;
        }

        return $this->id($value, $name);
    }

    private function id(mixed $value, string $name): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_int($id)) {
            throw new InvalidArgumentException($name.' must be a positive integer.');
        }

        return $id;
    }

    private function requiredArgument(string $name, string $label): string
    {
        $value = $this->documentArgument($name);

        return is_string($value) && $value !== '' ? $value : $this->promptText($label);
    }

    /** @param (Closure(string): ?string)|null $validate */
    private function promptText(string $label, ?Closure $validate = null): string
    {
        if (! $this->consoleMode()->mayPrompt) {
            throw new InvalidArgumentException($label.' is required.');
        }

        $value = $this->commandPrompts()->run(fn (): TextPrompt => new TextPrompt($label, required: true, validate: $validate));
        if (! is_string($value)) {
            throw new InvalidArgumentException('Input was cancelled.');
        }

        return $value;
    }

    private function documentName(mixed $value, string $label): string
    {
        $validate = static function (string $name): ?string {
            $normalized = Normalizer::normalize($name);
            if ($normalized === false || strlen($normalized) < 1 || strlen($normalized) > 255
                || preg_match('~[\\\\/\p{C}]|^\s|\s$~u', $normalized) !== 0 || in_array($normalized, ['.', '..'], true)) {
                return 'Use a name of 1–255 bytes without paths, controls, or surrounding whitespace.';
            }

            return null;
        };
        $value ??= $this->promptText($label, $validate);
        if (! is_string($value) || ($error = $validate($value)) !== null) {
            throw new InvalidArgumentException($error ?? 'Use a valid Document name.');
        }

        return $value;
    }

    private function outputPath(): string
    {
        $validate = static function (string $path): ?string {
            if ($path === '-') {
                return null;
            }
            if (file_exists($path) || is_link($path)) {
                return 'Download never overwrites an existing path.';
            }
            if (! is_dir(dirname($path)) || ! is_writable(dirname($path))) {
                return 'Choose a writable output directory.';
            }

            return null;
        };
        $path = $this->stringOption('output') ?? $this->promptText('Output path', $validate);
        if (($error = $validate($path)) !== null) {
            throw new InvalidArgumentException($error);
        }

        return $path;
    }

    private function source(bool $inline): string
    {
        $content = $this->getNativeDefinition()->hasOption('content') ? $this->documentOption('content') : null;
        if (is_string($content)) {
            return $this->validateBytes($content, $inline);
        }
        $path = $this->stringOption('from');
        if ($path !== null) {
            return $this->readSource($path, $inline);
        }
        $bytes = null;
        $this->promptText('Local file path', function (string $path) use ($inline, &$bytes): ?string {
            try {
                $bytes = $this->readSource($path, $inline);

                return null;
            } catch (InvalidArgumentException $exception) {
                return $exception->getMessage();
            }
        });

        return $bytes ?? throw new RuntimeException('Input bytes were not validated.');
    }

    private function readSource(string $path, bool $inline): string
    {
        if ($path !== '-' && (! is_file($path) || ! is_readable($path))) {
            throw new InvalidArgumentException('Cannot read the local input file.');
        }
        try {
            $stream = $path === '-' ? STDIN : @fopen($path, 'rb');
            if ($stream === false) {
                throw new InvalidArgumentException('Cannot read the local input file.');
            }
            try {
                $bytes = @stream_get_contents($stream, 10485761);
            } finally {
                if ($path !== '-') {
                    @fclose($stream);
                }
            }
            if ($bytes === false) {
                throw new InvalidArgumentException('Cannot read input bytes.');
            }
        } catch (ErrorException) {
            throw new InvalidArgumentException('Cannot read the local input file.');
        }

        return $this->validateBytes($bytes, $inline);
    }

    private function validateBytes(#[\SensitiveParameter] string $bytes, bool $inline): string
    {
        if (strlen($bytes) > ($inline ? 1048576 : 10485760)) {
            throw new InvalidArgumentException('Document content is too large.');
        }
        if ($inline && (! mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0"))) {
            throw new InvalidArgumentException('Inline content must be UTF-8 without NUL.');
        }

        return $bytes;
    }

    private function renderResult(ProjectDocumentResponse|ProjectDocumentsResponse|ProjectDocumentVersionsResponse|RemovedProjectDocumentResponse $response): void
    {
        if ($response instanceof ProjectDocumentsResponse) {
            $rows = array_map(fn (ProjectDocumentResponse $item): array => [(string) $item->id, $item->kind, $this->verb === 'search' ? $item->path : $item->name, (string) $item->revision, $item->currentVersion?->sizeBytes === null ? '—' : (string) $item->currentVersion->sizeBytes, $item->isArchived ? 'yes' : 'no'], $response->entries);
            ConsoleWriter::write($this->output, $this->humanRenderer()->table(['ID', 'KIND', $this->verb === 'search' ? 'PATH' : 'NAME', 'REVISION', 'SIZE', 'ARCHIVED'], $rows, 'No matching Documents.'));
        } elseif ($response instanceof ProjectDocumentVersionsResponse) {
            $rows = array_map(static fn ($item): array => [(string) $item->number, (string) $item->id, $item->mediaType, (string) $item->sizeBytes, $item->sha256, $item->createdAt], $response->versions);
            ConsoleWriter::write($this->output, $this->humanRenderer()->table(['NUMBER', 'ID', 'TYPE', 'SIZE', 'SHA256', 'CREATED'], $rows, 'No versions.'));
        } elseif ($response instanceof ProjectDocumentResponse) {
            ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Document: '.$response->path, ['ID' => $response->id, 'Kind' => $response->kind, 'Revision' => $response->revision, 'Archived' => $response->isArchived, 'Version' => $response->currentVersion?->number, 'Media type' => $response->currentVersion?->mediaType, 'Size' => $response->currentVersion?->sizeBytes, 'SHA256' => $response->currentVersion?->sha256, 'Request ID' => $response->requestId]));
        } else {
            $this->writeHumanMessage('Document removed. Request ID: '.$response->requestId);
        }
    }
}
