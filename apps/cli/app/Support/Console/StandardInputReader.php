<?php

declare(strict_types=1);

namespace App\Support\Console;

interface StandardInputReader
{
    public function read(): string;
}
