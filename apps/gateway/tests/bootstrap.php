<?php

declare(strict_types=1);
use Tests\Support\TestDatabaseEnvironment;
use Tests\Support\TestOrbitHome;

require dirname(__DIR__).'/vendor/autoload.php';

TestDatabaseEnvironment::bootstrap();
TestOrbitHome::bootstrap();
