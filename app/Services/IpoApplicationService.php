<?php

namespace App\Services;

use App\Models\DematAccount;
use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\PanCard;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Creates one or more IPO applications for a user in a single bulk submission
 * (all rows share a batch_id) and lists an individual user's applications.
 * Shares/amount are snapshotted at submission time from the IPO's price band
 * and lot size so later price changes never distort a stored bid.
 */
class IpoApplicationService
{
    /**
     * Shared row shape: {pan_card_id, demat_account_id, trading_account_id?,
     * applications: [{ipo_id, lots}]}. Validation lives in the FormRequest;
     * this service coerces defensively for the broker scalar layer.
     *
     * @param  array<string, mixed>  $data
     * @return array{batch_id: string, applications: array<int, IpoApplication>}
     */
    public function createBulk(User $user, array $data): array
    {
        $panCardId = (string) ($data['pan_card_id'] ?? '');
        $dematAccountId = (string) ($data['demat_account_id'] ?? '');

        $pan = PanCard::where('user_id', $user->id)->findOrFail($panCardId);
        $demat = DematAccount::where('user_id', $user->id)->findOrFail($dematAccountId);

        $tradingAccount = null;
        if (isset($data['trading_account_id']) && is_string($data['trading_account_id'])) {
            $tradingAccount = $user->tradingAccounts()->findOrFail($data['trading_account_id']);
        }

        $batchId = (string) Str::uuid();
        $applications = [];

        foreach ((array) ($data['applications'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $ipoId = (string) ($row['ipo_id'] ?? '');
            $lots = max(1, (int) ($row['lots'] ?? 1));

            if ($ipoId === '') {
                continue;
            }

            $ipo = Ipo::findOrFail($ipoId);
            $lotSize = (int) ($ipo->lot_size ?? 0);
            $priceMax = (float) ($ipo->price_max ?? 0);

            $applications[] = IpoApplication::updateOrCreate(
                ['user_id' => $user->id, 'ipo_id' => $ipo->id, 'pan_card_id' => $pan->id],
                [
                    'demat_account_id' => $demat->id,
                    'trading_account_id' => $tradingAccount?->id,
                    'batch_id' => $batchId,
                    'lots' => $lots,
                    'shares' => $lotSize > 0 ? $lotSize * $lots : null,
                    'amount' => $lotSize > 0 && $priceMax > 0 ? $priceMax * $lotSize * $lots : null,
                    'price_per_share' => $priceMax > 0 ? $priceMax : null,
                    'status' => 'queued',
                ],
            );
        }

        return [
            'batch_id' => $batchId,
            'applications' => $applications,
        ];
    }

    /**
     * @return LengthAwarePaginator<int, IpoApplication>
     */
    public function forUser(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return IpoApplication::with(['ipo:id,slug,name,symbol,open_date,close_date,listing_date,price_min,price_max', 'panCard:id,pan_number', 'dematAccount:id,provider,client_id', 'allotment'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Scoped lookup for update/withdraw/refresh actions.
     */
    public function scoped(User $user, string $id): IpoApplication
    {
        $application = IpoApplication::find($id);

        if ($application === null || $application->user_id !== $user->id) {
            throw (new ModelNotFoundException)->setModel(IpoApplication::class, $id);
        }

        return $application;
    }
}
