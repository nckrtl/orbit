<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/** A console output whose standard output and standard error are kept apart in memory. */
final class CapturedConsoleOutput extends StreamOutput implements ConsoleOutputInterface
{
    private OutputInterface $errors;

    public function __construct()
    {
        parent::__construct(fopen('php://memory', 'w+b'), decorated: false);
        $this->errors = new StreamOutput(fopen('php://memory', 'w+b'), decorated: false);
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->errors;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->errors = $error;
    }

    public function section(): ConsoleSectionOutput
    {
        throw new \LogicException('Sections are not captured.');
    }

    public function stdout(): string
    {
        return self::read($this);
    }

    public function stderr(): string
    {
        return $this->errors instanceof StreamOutput ? self::read($this->errors) : '';
    }

    private static function read(StreamOutput $output): string
    {
        rewind($output->getStream());

        return (string) stream_get_contents($output->getStream());
    }
}
