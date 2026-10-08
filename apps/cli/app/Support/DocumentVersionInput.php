<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;

/** Keeps a Document's --version value from becoming Symfony's application-version switch. */
final readonly class DocumentVersionInput implements InputInterface
{
    public function __construct(private InputInterface $input) {}

    public function getFirstArgument(): ?string
    {
        return $this->input->getFirstArgument();
    }

    /** @param string|array<string> $values */
    public function hasParameterOption(string|array $values, bool $onlyParams = false): bool
    {
        if ($values === ['--version', '-V']) {
            return $this->input->hasParameterOption('-V', $onlyParams);
        }

        return $this->input->hasParameterOption($values, $onlyParams);
    }

    /**
     * @param  string|array<string>  $values
     * @param  array<mixed>|string|bool|int|float|null  $default
     */
    public function getParameterOption(string|array $values, string|bool|int|float|array|null $default = false, bool $onlyParams = false): mixed
    {
        return $this->input->getParameterOption($values, $default, $onlyParams);
    }

    public function bind(InputDefinition $definition): void
    {
        $this->input->bind($definition);
    }

    public function validate(): void
    {
        $this->input->validate();
    }

    /** @return array<string, mixed> */
    public function getArguments(): array
    {
        return $this->input->getArguments();
    }

    public function getArgument(string $name): mixed
    {
        return $this->input->getArgument($name);
    }

    public function setArgument(string $name, mixed $value): void
    {
        $this->input->setArgument($name, $value);
    }

    public function hasArgument(string $name): bool
    {
        return $this->input->hasArgument($name);
    }

    /** @return array<string, mixed> */
    public function getOptions(): array
    {
        return $this->input->getOptions();
    }

    public function getOption(string $name): mixed
    {
        return $this->input->getOption($name);
    }

    public function setOption(string $name, mixed $value): void
    {
        $this->input->setOption($name, $value);
    }

    public function hasOption(string $name): bool
    {
        return $this->input->hasOption($name);
    }

    public function isInteractive(): bool
    {
        return $this->input->isInteractive();
    }

    public function setInteractive(bool $interactive): void
    {
        $this->input->setInteractive($interactive);
    }

    public function __toString(): string
    {
        return (string) $this->input;
    }
}
