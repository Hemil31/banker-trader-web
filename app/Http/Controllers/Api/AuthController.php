<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\AuthService;
use App\Traits\ResponseStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use ResponseStructure;

    public function __construct(
        protected AuthService $authService,
    ) {}

    /**
     * Handle a login request.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->attemptLogin(
            $request->email,
            $request->password,
        );

        $data = $result->getData(true);

        if ($data['success']) {
            return $this->successResponse($data['data'], 'Login successful.');
        }

        return $this->errorResponse($result->getStatusCode(), $data['message'] ?? 'Invalid credentials.');
    }

    /**
     * Refresh an access token.
     */
    public function refreshToken(Request $request): JsonResponse
    {
        $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $result = $this->authService->refreshToken($request->refresh_token);
        $data = $result->getData(true);

        if ($data['success']) {
            return $this->successResponse($data['data'], 'Token refreshed successfully.');
        }

        return $this->errorResponse($result->getStatusCode(), $data['message'] ?? 'Failed to refresh token.', $data['errors'] ?? null);
    }

    /**
     * Logout the authenticated user.
     */
    public function logout(Request $request): JsonResponse
    {
        return $this->authService->logout($request->user());
    }

    /**
     * Return the current authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return $this->successResponse($request->user(), 'User retrieved successfully.');
    }

    /**
     * Send a password reset link to the given email.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        return $this->authService->sendPasswordResetLink($request->email);
    }

    /**
     * Reset the user's password using a valid token.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $result = $this->authService->resetPassword(
            $request->only(['email', 'password', 'password_confirmation', 'token']),
        );

        $data = $result->getData(true);

        if ($data['success']) {
            return $this->successResponse(null, 'Password reset successfully.');
        }

        return $this->errorResponse($result->getStatusCode(), $data['message'] ?? 'Unable to reset password.', $data['errors'] ?? null);
    }

    /**
     * Change the password of the authenticated user.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $result = $this->authService->changePassword(
            $request->user(),
            $request->current_password,
            $request->new_password,
        );

        $data = $result->getData(true);

        if ($data['success']) {
            return $this->successResponse(null, 'Password changed successfully.');
        }

        return $this->errorResponse($result->getStatusCode(), $data['message'] ?? 'Unable to change password.', $data['errors'] ?? null);
    }
}
