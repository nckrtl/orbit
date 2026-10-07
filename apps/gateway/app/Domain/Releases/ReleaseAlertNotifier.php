<?php

declare(strict_types=1);

namespace App\Domain\Releases;

/**
 * Raises a release alert: an Activity entry, a problem for the outer loop, and the opt-in signed webhook.
 *
 * Every part is recorded on its own and a failed part never stops the others. It never throws,
 * so a release command can raise an alert from its failure path. Call it outside a database
 * transaction: a rollback would discard the Activity entry and the problem after the webhook went out.
 */
interface ReleaseAlertNotifier
{
    public function alert(ReleaseAlert $alert): ReleaseAlertReceipt;
}
