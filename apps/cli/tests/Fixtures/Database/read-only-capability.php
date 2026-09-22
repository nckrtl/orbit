<?php

declare(strict_types=1);

use App\Services\Database\DynamicPdoConnection;
use App\Services\Database\LocalDatabaseQueryException;

require __DIR__.'/../../../app/Services/Database/LocalDatabaseQueryException.php';
require __DIR__.'/../../../app/Services/Database/DynamicPdoConnection.php';

try {
    (new DynamicPdoConnection)->connect(['driver' => 'sqlite', 'path' => stream_get_contents(STDIN)], false);
} catch (LocalDatabaseQueryException $exception) {
    echo json_encode(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], JSON_THROW_ON_ERROR)."\n";

    exit(1);
}

exit(0);
