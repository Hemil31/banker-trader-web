<?php

namespace App\Contracts\Ipo;

/**
 * Default allotment source: no live registrar integration yet. Returns no
 * result, so checks record attempts/checked_at but leave the application
 * pending until a real AllotmentProvider binding is wired.
 */
class NoopAllotmentProvider implements AllotmentProvider
{
    public function fetch(string $panNumber, string $applicationNumber, ?string $registrar = null): array
    {
        return [];
    }
}
