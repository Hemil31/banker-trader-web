<?php

namespace App\Contracts\Ipo;

/**
 * Pluggable source of IPO records (today a local JSON mirror of IPO Ji).
 * The ingest service consumes only this interface, so a live scraper/API can
 * be swapped in without touching engine code.
 */
interface IpoProvider
{
    /**
     * Fetch all known IPOs and their day-wise subscription / GMP history.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(): array;
}
