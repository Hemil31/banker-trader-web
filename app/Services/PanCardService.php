<?php

namespace App\Services;

use App\Models\PanCard;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * CRUD + manual verification for a user's linked PAN cards. Normalizes the PAN
 * to the canonical uppercase form and keeps the primary flag exclusive.
 */
class PanCardService
{
    /**
     * @return Collection<int, PanCard>
     */
    public function list(User $user): Collection
    {
        return PanCard::where('user_id', $user->id)->orderByDesc('created_at')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): PanCard
    {
        $data['user_id'] = $user->id;
        $data['pan_number'] = static::normalize((string) $data['pan_number']);

        if (! empty($data['is_primary'])) {
            $this->clearPrimary($user);
        }

        return PanCard::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, string $id, array $data): PanCard
    {
        $card = PanCard::where('user_id', $user->id)->findOrFail($id);

        if (isset($data['pan_number'])) {
            $data['pan_number'] = static::normalize((string) $data['pan_number']);
        }

        if (! empty($data['is_primary'])) {
            $this->clearPrimary($user);
        }

        $card->update($data);

        return $card->refresh();
    }

    public function delete(User $user, string $id): void
    {
        PanCard::where('user_id', $user->id)->findOrFail($id)->delete();
    }

    public function verify(User $user, string $id, string $source = 'manual'): PanCard
    {
        $card = PanCard::where('user_id', $user->id)->findOrFail($id);

        $card->update([
            'status' => 'verified',
            'verified_at' => now(),
            'verification_details' => [
                'source' => $source,
                'verified_at' => now()->toIso8601String(),
            ],
        ]);

        return $card->refresh();
    }

    /**
     * PAN is 5 letters + 4 digits + 1 letter; strip spaces and uppercase.
     */
    public static function normalize(string $pan): string
    {
        return strtoupper(preg_replace('/\s+/', '', $pan) ?? $pan);
    }

    protected function clearPrimary(User $user): void
    {
        PanCard::where('user_id', $user->id)->where('is_primary', true)->update(['is_primary' => false]);
    }
}
