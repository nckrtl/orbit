<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Instances\Apps\AppProjectionStepAdapter;

/** Bound to explicit old/candidate contexts, not a mutable public app map. */
interface AppProjectionEnvironment extends AppProjectionStepAdapter {}
