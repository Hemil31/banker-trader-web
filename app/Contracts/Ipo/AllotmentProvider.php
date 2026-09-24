<?php

namespace App\Contracts\Ipo;

/**
 * Pluggable allotment lookup source (e.g. a registrar search by PAN/application
 * number). The default NoopAllotmentProvider returns empty until a real
 * registrar/NSDL source is wired.
 */
interface AllotmentProvider
{
    /**
     * Look up the allotment result for one application.
     *
     * @return array{
     *     result?: 'allotted'|'not_allotted',
     *     shares_allotted?: int,
     *     source?: string,
     *     raw?: array<string, mixed>
     * }
     */
    public function fetch(string $panNumber, string $applicationNumber, ?string $registrar = null): array;
}
