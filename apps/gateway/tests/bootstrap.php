<?php

declare(strict_types=1);
use Tests\Support\TestDatabaseEnvironment;
use Tests\Support\TestOrbitHome;
use Tests\Support\TestTemporaryDirectory;

require dirname(__DIR__).'/vendor/autoload.php';

TestTemporaryDirectory::bootstrap();
TestDatabaseEnvironment::bootstrap();
TestOrbitHome::bootstrap();
