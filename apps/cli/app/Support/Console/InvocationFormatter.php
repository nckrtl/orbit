<?php

declare(strict_types=1);

namespace App\Support\Console;

use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Formatter\OutputFormatterStyleInterface;
use Symfony\Component\Console\Formatter\WrappableOutputFormatterInterface;

/** Keeps framework formatting while constraining decoration to this output stream. */
final readonly class InvocationFormatter implements WrappableOutputFormatterInterface
{
    public function __construct(private OutputFormatterInterface $formatter, private bool $mayDecorate)
    {
        $this->setDecorated($formatter->isDecorated());
    }

    public function setDecorated(bool $decorated): void
    {
        $this->formatter->setDecorated($decorated && $this->mayDecorate);
    }

    public function isDecorated(): bool
    {
        return $this->formatter->isDecorated();
    }

    public function setStyle(string $name, OutputFormatterStyleInterface $style): void
    {
        $this->formatter->setStyle($name, $style);
    }

    public function hasStyle(string $name): bool
    {
        return $this->formatter->hasStyle($name);
    }

    public function getStyle(string $name): OutputFormatterStyleInterface
    {
        return $this->formatter->getStyle($name);
    }

    public function format(?string $message): ?string
    {
        return $this->formatter->format($message);
    }

    public function formatAndWrap(?string $message, int $width): string
    {
        return $this->formatter instanceof WrappableOutputFormatterInterface
            ? $this->formatter->formatAndWrap($message, $width)
            : ($this->formatter->format($message) ?? '');
    }
}
