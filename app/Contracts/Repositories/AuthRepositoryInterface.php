<?php

namespace App\Contracts\Repositories;

use App\Models\User;

interface AuthRepositoryInterface
{
    /**
     * Find a user by username or email.
     */
    public function findByUsernameOrEmail(string $identifier): ?User;

    /**
     * Find a user by credentials (login identifier + password).
     */
    public function findByCredentials(string $identifier, string $password): ?User;

    /**
     * Update the user's last login timestamp.
     */
    public function updateLastLogin(string $userId): bool;

    /**
     * Update the user's logged-in status timestamp.
     */
    public function updateLoginStatus(string $userId, bool $isLoggedIn): bool;

    /**
     * Update the user's password.
     */
    public function updatePassword(string $userId, string $newPassword): bool;
}
