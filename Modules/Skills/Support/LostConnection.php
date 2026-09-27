<?php

namespace Modules\Skills\Support;

use Illuminate\Database\DetectsLostConnections;
use Throwable;

/**
 * A public window onto the framework's lost-connection patterns —
 * the trait's causedByLostConnection() is protected on Connection,
 * but the RSD importer must tell an infrastructure outage (rethrow:
 * importing on is pointless, and every remaining file would
 * masquerade as a bad descriptor) from a data-level storage
 * rejection (skip that one file, keep the batch going).
 */
class LostConnection
{
    use DetectsLostConnections;

    public function check(Throwable $exception): bool
    {
        return $this->causedByLostConnection($exception);
    }
}
