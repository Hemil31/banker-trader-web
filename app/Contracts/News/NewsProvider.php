<?php

namespace App\Contracts\News;

/**
 * Pluggable news provider. Today we ship the FreeNewsApiProvider (free global
 * news REST API). The signal scanner consumes only this interface, so a
 * different provider can be swapped in without touching engine code.
 */
interface NewsProvider
{
    /**
     * Fetch a lightweight list of articles matching a query.
     *
     * @param  array<string, mixed>  $filters  country, language, topic,
     *                                         published_after, published_before, order_by
     * @return array<int, array{
     *     uuid: string,
     *     title: string,
     *     published_at: string,
     *     publisher: string
     * }>
     */
    public function fetch(string $query, array $filters = []): array;

    /**
     * Fetch full details for a single article by uuid (link, thumbnail,
     * authors, topics). Used for on-demand enrichment — not during scans.
     *
     * @return array{
     *     uuid: string,
     *     title: string,
     *     published_at: string,
     *     publisher: ?string,
     *     original_url: ?string,
     *     thumbnail: ?string,
     *     authors: array<int, string>,
     *     topics: array<int, string>
     * }
     */
    public function fetchDetails(string $uuid): array;

    /**
     * Free-tier quota reported by the provider on the last list request
     * (daily limit / remaining / reset time). Empty when not yet sampled.
     *
     * @return array{limit_day: ?int, remaining_day: ?int, reset_day: ?string}
     */
    public function quota(): array;
}
