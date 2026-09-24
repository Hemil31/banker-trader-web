<?php

namespace App\Services;

use App\Models\Ipo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read-side queries for the IPO module (list + detail with subscription and
 * GMP history). List is ordered with TBA-date entries last, then by open date.
 */
class IpoService
{
    /**
     * @return LengthAwarePaginator<int, Ipo>
     */
    public function list(?string $status = null, ?string $board = null, int $perPage = 15): LengthAwarePaginator
    {
        return Ipo::query()
            ->when($status !== null && $status !== '', fn ($q) => $q->where('status', $status))
            ->when($board !== null && $board !== '', fn ($q) => $q->where('board', $board))
            ->orderByRaw('open_date IS NULL, open_date ASC')
            ->orderBy('open_date')
            ->paginate($perPage);
    }

    public function findBySlug(string $slug): Ipo
    {
        return Ipo::with(['subscriptionSnapshots', 'gmpHistory'])
            ->where('slug', $slug)
            ->firstOrFail();
    }
}
