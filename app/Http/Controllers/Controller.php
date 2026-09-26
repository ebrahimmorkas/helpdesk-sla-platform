<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    protected const DEFAULT_PER_PAGE = 25;

    protected const MAX_PER_PAGE = 100;

    protected function perPage(Request $request): int
    {
        return max(1, min(self::MAX_PER_PAGE, $request->integer('per_page', self::DEFAULT_PER_PAGE)));
    }

    /**
     * Resolve a "sort" query parameter such as "-created_at" against an allow-list,
     * so user input never reaches ORDER BY directly.
     *
     * @param  list<string>  $allowed
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    protected function sort(Request $request, array $allowed, string $default): array
    {
        $sort = (string) $request->query('sort', $default);

        if (! in_array(ltrim($sort, '-'), $allowed, true)) {
            $sort = $default;
        }

        return [ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc'];
    }
}
