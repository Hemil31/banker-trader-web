<?php

namespace App\Contracts\Ipo;

use RuntimeException;

/**
 * Reads a local JSON mirror of IPO Ji. Top level is either a plain list of
 * IPO items or an object with an `ipos` key — both shapes are accepted so a
 * scraper can export its raw crawl verbatim.
 */
class JsonFileIpoProvider implements IpoProvider
{
    public function __construct(protected string $path) {}

    public function fetchAll(): array
    {
        if (! is_file($this->path)) {
            throw new RuntimeException("IPO source file not found: {$this->path}");
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("IPO source file is not valid JSON: {$this->path}");
        }

        $items = isset($decoded['ipos']) && is_array($decoded['ipos'])
            ? $decoded['ipos']
            : $decoded;

        if (! array_is_list($items)) {
            throw new RuntimeException('IPO source file must contain a list of IPO items');
        }

        return $items;
    }
}
