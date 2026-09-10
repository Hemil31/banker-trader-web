<?php

namespace App\Repositories;

use App\Contracts\Repositories\AuthRepositoryInterface;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthRepository implements AuthRepositoryInterface
{
    /**
     * Find a user by username or email.
     */
    public function findByUsernameOrEmail(string $identifier): ?User
    {
        return User::where('username', $identifier)
            ->orWhere('email', $identifier)
            ->first();
    }

    /**
     * Find a user by credentials (login identifier + password).
     */
    public function findByCredentials(string $identifier, string $password): ?User
    {
        $user = $this->findByUsernameOrEmail($identifier);

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        return $user;
    }

    /**
     * Update the user's last login timestamp.
     */
    public function updateLastLogin(string $userId): bool
    {
        return (bool) User::where('id', $userId)->update([
            'last_login' => now(),
        ]);
    }

    /**
     * Update the user's logged-in status timestamp.
     */
    public function updateLoginStatus(string $userId, bool $isLoggedIn): bool
    {
        return (bool) User::where('id', $userId)->update([
            'is_login' => $isLoggedIn ? now() : null,
        ]);
    }

    /**
     * Update the user's password.
     */
    public function updatePassword(string $userId, string $newPassword): bool
    {
        return (bool) User::where('id', $userId)->update([
            'password' => Hash::make($newPassword),
        ]);
    }
}
