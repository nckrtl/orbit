<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Documentation\DocumentationRepository;
use App\Documentation\MarkdownProse;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;
use JsonException;

/**
 * Checks Markdown links, MDX component links, Mintlify navigation paths, and redirect destinations.
 * Mintlify's build validator owns MDX syntax and the complete docs.json schema.
 */
final readonly class MintlifyLinksRule implements GroupedRule
{
    public const string RULE = 'orbit.mintlify_links';

    public function __construct(private DocumentationRepository $repository) {}

    public function group(): string
    {
        return 'references';
    }

    public function check(): array
    {
        $findings = [];
        $mintlify = is_file($this->repository->docsPath.'/docs.json');

        foreach ($this->repository->markdownDocuments() as $path => $contents) {
            foreach (MarkdownProse::lines($contents) as $lineNumber => $line) {
                $line = preg_replace('/`[^`]*`/', '', $line) ?? $line;
                preg_match_all('/\[[^\]]*\]\((<[^>]+>|(?:[^\s()]|\((?1)\))+)(?:\s+"[^"]*")?\)|\bhref=["\']([^"\']+)["\']/', $line, $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $target = trim($match[2] ?? $match[1], '<>');
                    if (! $this->exists($path, $target)) {
                        $findings[] = $this->missing($path, $target, $lineNumber);
                    } elseif ($mintlify && $this->usesRepositoryUrl($target)) {
                        $findings[] = new Finding(
                            path: $path,
                            line: $lineNumber,
                            severity: FindingSeverity::Error,
                            rule: self::RULE,
                            message: "Use a root-relative page URL without a file extension instead of [{$target}].",
                        );
                    }
                }
            }
        }

        return [...$findings, ...$this->navigationFindings()];
    }

    /** @return list<Finding> */
    private function navigationFindings(): array
    {
        $configPath = $this->repository->docsPath.'/docs.json';
        if (! is_file($configPath)) {
            return [];
        }

        try {
            $config = json_decode(file_get_contents($configPath) ?: '', true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [new Finding('docs/docs.json', null, FindingSeverity::Error, self::RULE, 'Mintlify configuration must contain valid JSON.')];
        }

        if (! is_array($config) || ! is_array($config['navigation'] ?? null)) {
            return [new Finding('docs/docs.json', null, FindingSeverity::Error, self::RULE, 'Mintlify configuration must define navigation.')];
        }

        return [...$this->navigationPages($config['navigation']), ...$this->redirectFindings($config['redirects'] ?? [])];
    }

    /**
     * A local redirect destination must name a page, and its anchor a heading on that page.
     *
     * @return list<Finding>
     */
    private function redirectFindings(mixed $redirects): array
    {
        $findings = [];
        foreach (is_array($redirects) ? $redirects : [] as $redirect) {
            $destination = is_array($redirect) ? ($redirect['destination'] ?? null) : null;
            if (! is_string($destination) || ! str_starts_with($destination, '/') || str_starts_with($destination, '//')) {
                continue;
            }

            [$target, $anchor] = explode('#', $destination, 2) + [1 => ''];
            $file = $this->resolve('docs/docs.json', $target);
            if ($file === null) {
                $findings[] = $this->missing('docs/docs.json', $destination);
            } elseif ($anchor !== '' && ! in_array($anchor, $this->headingAnchors($file), true)) {
                $findings[] = new Finding('docs/docs.json', null, FindingSeverity::Error, self::RULE, "Redirect destination [{$destination}] names no heading on its page.");
            }
        }

        return $findings;
    }

    /** @return list<string> Mintlify heading anchors: lower case, punctuation removed, spaces joined by hyphens. */
    private function headingAnchors(string $file): array
    {
        $contents = preg_replace('/^(`{3,}|~{3,}).*?^\1/ms', '', file_get_contents($file) ?: '') ?? '';
        preg_match_all('/^#{1,6}[ \t]+(.+?)[ \t#]*$/m', $contents, $matches);

        return array_map(static function (string $heading): string {
            $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $heading) ?? $heading;
            $text = preg_replace('/[^\w\s-]/u', '', mb_strtolower(trim($text))) ?? '';

            return preg_replace('/\s+/', '-', $text) ?? '';
        }, $matches[1]);
    }

    /**
     * @param  array<array-key, mixed>  $navigation
     * @return list<Finding>
     */
    private function navigationPages(array $navigation, ?string $openApi = null): array
    {
        $findings = [];
        $openApi = is_string($navigation['openapi'] ?? null) ? $navigation['openapi'] : $openApi;
        foreach ($navigation as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if ($key === 'pages') {
                foreach ($value as $page) {
                    if (is_string($page) && ! $this->exists('docs/docs.json', $page)
                        && ! $this->openApiOperationExists($openApi, $page)) {
                        $findings[] = $this->missing('docs/docs.json', $page);
                    }
                }
            }

            array_push($findings, ...$this->navigationPages($value, $openApi));
        }

        return $findings;
    }

    private function openApiOperationExists(?string $specification, string $page): bool
    {
        if ($specification === null || preg_match('/^(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS|TRACE) (\/\S+)$/', $page, $matches) !== 1) {
            return false;
        }

        $path = $this->repository->docsPath.'/'.ltrim($specification, '/');
        if (! is_file($path)) {
            return false;
        }

        try {
            $spec = json_decode(file_get_contents($path) ?: '', true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (! is_array($spec)) {
            return false;
        }

        $paths = $spec['paths'] ?? null;

        if (! is_array($paths)) {
            return false;
        }

        $pathItem = $paths[$matches[2]] ?? null;

        if (! is_array($pathItem)) {
            return false;
        }

        return is_array($pathItem[strtolower($matches[1])] ?? null);
    }

    private function exists(string $source, string $target): bool
    {
        if (str_starts_with($target, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $target) === 1) {
            return true;
        }

        $target = preg_replace('/[?#].*$/', '', $target) ?? '';

        return $target === '' || $this->resolve($source, $target) !== null;
    }

    /** The file a local page target names, or null. */
    private function resolve(string $source, string $target): ?string
    {
        $base = str_starts_with($target, '/')
            ? $this->repository->docsPath
            : dirname($this->repository->docsPath.'/'.substr($source, 5));
        $path = $base.'/'.rawurldecode(ltrim($target, '/'));

        foreach (['', '.md', '.mdx', '/index.md', '/index.mdx'] as $suffix) {
            if (is_file($path.$suffix)) {
                return $path.$suffix;
            }
        }

        return null;
    }

    private function usesRepositoryUrl(string $target): bool
    {
        if (str_starts_with($target, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $target) === 1) {
            return false;
        }

        $path = preg_replace('/[?#].*$/', '', $target) ?? '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['md', 'mdx'], true)
            || ($path !== '' && $extension === '' && ! str_starts_with($path, '/'));
    }

    private function missing(string $path, string $target, ?int $line = null): Finding
    {
        return new Finding(
            path: $path,
            severity: FindingSeverity::Error,
            rule: self::RULE,
            message: "Local documentation target [{$target}] does not exist.",
            line: $line,
        );
    }
}
