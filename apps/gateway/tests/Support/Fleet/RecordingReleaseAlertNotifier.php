<?php

declare(strict_types=1);

namespace Tests\Support\Fleet;

use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertReceipt;
use App\Domain\Releases\ReleaseAlertStep;

final class RecordingReleaseAlertNotifier implements ReleaseAlertNotifier
{
    /** @var list<ReleaseAlert> */
    public array $alerts = [];

    public function alert(ReleaseAlert $alert): ReleaseAlertReceipt
    {
        $this->alerts[] = $alert;

        return new ReleaseAlertReceipt('request-1', 7, ReleaseAlertStep::done(), ReleaseAlertStep::skipped('not_configured'));
    }
}
