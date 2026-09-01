<?php

declare(strict_types=1);

namespace App\Documentation;

use UnexpectedValueException;

final readonly class DocumentationContextEntry
{
    public string $path;

    public string $title;

    public string $kind;

    /** @var list<string> */
    public array $components;

    /** @var list<string> */
    public array $concepts;

    /** @var list<string> */
    public array $governingAdrs;

    /**
     * @param array{
     *     path:string,
     *     title:string,
     *     kind:string,
     *     components:list<string>,
     *     concepts:list<string>,
     *     governing_adrs:list<string>
     * } $data
     */
    public function __construct(array $data)
    {
        $this->path = $data['path'];
        $this->title = $data['title'];
        $this->kind = $data['kind'];
        $this->components = $data['components'];
        $this->concepts = $data['concepts'];
        $this->governingAdrs = $data['governing_adrs'];
    }

    /** @return array{path:string,title:string,kind:string,components:list<string>,concepts:list<string>,governing_adrs:list<string>} */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'title' => $this->title,
            'kind' => $this->kind,
            'components' => $this->components,
            'concepts' => $this->concepts,
            'governing_adrs' => $this->governingAdrs,
        ];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $path = $data['path'] ?? null;
        $title = $data['title'] ?? null;
        $kind = $data['kind'] ?? null;

        if (! is_string($path) || ! is_string($title) || ! is_string($kind)) {
            throw new UnexpectedValueException('Documentation context entries require path, title, and kind strings.');
        }

        return new self([
            'path' => $path,
            'title' => $title,
            'kind' => $kind,
            'components' => self::stringList($data['components'] ?? null),
            'concepts' => self::stringList($data['concepts'] ?? null),
            'governing_adrs' => self::stringList($data['governing_adrs'] ?? null),
        ]);
    }

    /** @param list<string> $components @param list<string> $concepts */
    public function matches(array $components, array $concepts): bool
    {
        if ($components === [] && $concepts === []) {
            return true;
        }

        return (
            array_intersect($components, $this->components) !== []
            || array_intersect($concepts, $this->concepts) !== []
        );
    }

    public function priority(): int
    {
        return match ($this->kind) {
            'decision' => 0,
            'product' => 1,
            'domain' => 2,
            'reference' => 3,
            'solution' => 4,
            default => 5,
        };
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            throw new UnexpectedValueException('Documentation context entry lists must be arrays.');
        }

        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new UnexpectedValueException('Documentation context entry lists must contain strings.');
            }

            $strings[] = $item;
        }

        return $strings;
    }
}
