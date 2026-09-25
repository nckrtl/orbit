<?php

declare(strict_types=1);
use Tests\Support\TestDatabaseEnvironment;
use Tests\Support\TestOrbitHome;
use Tests\Support\TestTemporaryDirectory;
use Tests\Support\TestToolchain;

require dirname(__DIR__).'/vendor/autoload.php';

TestTemporaryDirectory::bootstrap();
TestDatabaseEnvironment::bootstrap();
TestOrbitHome::bootstrap();
TestToolchain::bootstrap();
