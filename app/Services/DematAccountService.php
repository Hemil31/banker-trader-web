<?php

namespace App\Services;

use App\Models\DematAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * CRUD + manual verification for a user's linked NSDL/CDSL demat accounts.
 * Keeps the primary flag exclusive per user.
 */
class DematAccountService
{
    /**
     * @return Collection<int, DematAccount>
     */
    public function list(User $user): Collection
    {
        return DematAccount::where('user_id', $user->id)->orderByDesc('created_at')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): DematAccount
    {
        $data['user_id'] = $user->id;

        if (! empty($data['is_primary'])) {
            $this->clearPrimary($user);
        }

        return DematAccount::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, string $id, array $data): DematAccount
    {
        $account = DematAccount::where('user_id', $user->id)->findOrFail($id);

        if (! empty($data['is_primary'])) {
            $this->clearPrimary($user);
        }

        $account->update($data);

        return $account->refresh();
    }

    public function delete(User $user, string $id): void
    {
        DematAccount::where('user_id', $user->id)->findOrFail($id)->delete();
    }

    public function verify(User $user, string $id, string $source = 'manual'): DematAccount
    {
        $account = DematAccount::where('user_id', $user->id)->findOrFail($id);

        $account->update([
            'status' => 'verified',
            'verified_at' => now(),
            'verification_details' => [
                'source' => $source,
                'verified_at' => now()->toIso8601String(),
            ],
        ]);

        return $account->refresh();
    }

    protected function clearPrimary(User $user): void
    {
        DematAccount::where('user_id', $user->id)->where('is_primary', true)->update(['is_primary' => false]);
    }
}
