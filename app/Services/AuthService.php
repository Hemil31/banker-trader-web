<?php

namespace App\Services;

use App\Contracts\Repositories\AuthRepositoryInterface;
use App\Models\User;
use App\Traits\ResponseStructure;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use League\OAuth2\Server\AuthorizationServer;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class AuthService
{
    use ResponseStructure;

    public function __construct(
        protected AuthRepositoryInterface $userRepository,
        protected AuthorizationServer $server,
    ) {}

    /**
     * Issue an OAuth password-grant token pair for the given user, in process.
     *
     * @return array<string, mixed>
     */
    protected function issuePasswordGrant(string $email, string $password): array
    {
        $request = (new PsrHttpFactory)->createRequest(
            SymfonyRequest::create(config('app.url'), 'POST', [
                'grant_type' => 'password',
                'client_id' => config('passport.client_id'),
                'client_secret' => config('passport.client_secret'),
                'username' => $email,
                'password' => $password,
                'scope' => '',
            ]),
        );

        return json_decode(
            $this->server->respondToAccessTokenRequest($request, app(ResponseInterface::class))
                ->getBody()
                ->__toString(),
            true,
        );
    }

    /**
     * Issue a new access token using a refresh token, in process.
     *
     * @return array<string, mixed>
     */
    protected function issueRefreshGrant(string $refreshToken): array
    {
        $request = (new PsrHttpFactory)->createRequest(
            SymfonyRequest::create(config('app.url'), 'POST', [
                'grant_type' => 'refresh_token',
                'client_id' => config('passport.client_id'),
                'client_secret' => config('passport.client_secret'),
                'refresh_token' => $refreshToken,
                'scope' => '',
            ]),
        );

        return json_decode(
            $this->server->respondToAccessTokenRequest($request, app(ResponseInterface::class))
                ->getBody()
                ->__toString(),
            true,
        );
    }

    /**
     * Attempt to authenticate a user and issue a Passport token.
     *
     * @return JsonResponse
     */
    public function attemptLogin(string $identifier, string $password)
    {
        $user = $this->userRepository->findByCredentials($identifier, $password);

        if (! $user) {
            return $this->errorResponse(401, 'Invalid credentials.', null);
        }

        try {
            $tokenData = $this->issuePasswordGrant($user->email, $password);

            if (isset($tokenData['error'])) {
                return $this->errorResponse(401, 'Failed to generate token.', $tokenData);
            }

            $this->userRepository->updateLastLogin($user->id);
            $this->userRepository->updateLoginStatus($user->id, true);

            $user['token'] = $tokenData;

            return $this->successResponse($user, 'Login successful.', 200);
        } catch (\Exception $e) {
            return $this->errorResponse(500, 'Error generating token.', null);
        }
    }

    /**
     * Refresh a Passport access token using a refresh token.
     *
     * @return JsonResponse
     */
    public function refreshToken(string $refreshToken)
    {
        try {
            $tokenData = $this->issueRefreshGrant($refreshToken);

            if (isset($tokenData['error'])) {
                return $this->errorResponse(401, 'Failed to refresh token.', $tokenData);
            }

            return $this->successResponse($tokenData, 'Token refreshed successfully.');
        } catch (\Exception $e) {
            return $this->errorResponse(500, 'Could not refresh token.', null);
        }
    }

    /**
     * Logout the authenticated user by deleting all their tokens.
     *
     * @return JsonResponse
     */
    public function logout(User $user)
    {
        $this->userRepository->updateLoginStatus($user->id, false);

        $user->tokens()->delete();

        return $this->successResponse(null, 'Logged out successfully.');
    }

    /**
     * Send a password reset link to the given email.
     *
     * @return JsonResponse
     */
    public function sendPasswordResetLink(string $email)
    {
        $status = Password::broker()->sendResetLink(['email' => $email]);

        if ($status === PasswordBroker::RESET_LINK_SENT) {
            return $this->successResponse(null, 'Password reset link sent successfully.');
        }

        if ($status === PasswordBroker::INVALID_USER) {
            return $this->errorResponse(404, 'We could not find a user with that email address.', null);
        }

        if ($status === PasswordBroker::RESET_THROTTLED) {
            return $this->errorResponse(429, 'Please wait before retrying.', null);
        }

        return $this->errorResponse(500, 'Unable to send password reset link.', null);
    }

    /**
     * Reset the user's password using a valid reset token.
     *
     * @param  array<string, mixed>  $credentials
     * @return JsonResponse
     */
    public function resetPassword(array $credentials)
    {
        $status = Password::broker()->reset($credentials, function (User $user, $password) {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));
        });

        if ($status === PasswordBroker::PASSWORD_RESET) {
            // Invalidate any existing tokens after a reset.
            if (isset($credentials['email']) && $user = $this->userRepository->findByUsernameOrEmail($credentials['email'])) {
                $user->tokens()->delete();
            }

            return $this->successResponse(null, 'Password reset successfully.');
        }

        if ($status === PasswordBroker::INVALID_TOKEN) {
            return $this->errorResponse(400, 'This password reset token is invalid.', null);
        }

        if ($status === PasswordBroker::INVALID_USER) {
            return $this->errorResponse(404, 'We could not find a user with that email address.', null);
        }

        return $this->errorResponse(500, 'Unable to reset password.', null);
    }

    /**
     * Change the password of the currently authenticated user.
     *
     * @return JsonResponse
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword)
    {
        if (! Hash::check($currentPassword, $user->password)) {
            return $this->errorResponse(422, 'Your current password is incorrect.', null);
        }

        $this->userRepository->updatePassword($user->id, $newPassword);

        // Revoke all tokens except the current one after a password change.
        $user->tokens()->delete();

        return $this->successResponse(null, 'Password changed successfully.');
    }
}
