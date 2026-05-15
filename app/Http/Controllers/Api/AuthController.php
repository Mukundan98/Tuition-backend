<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const REGISTERABLE_ROLE_SLUGS = ['teacher', 'student', 'parent'];

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'username' => ['nullable', 'string', 'max:50', 'regex:/^[a-zA-Z0-9._-]+$/', 'unique:users,username'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            'role' => ['required', 'string', 'in:'.implode(',', self::REGISTERABLE_ROLE_SLUGS)],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $role = Role::where('slug', $validated['role'])->firstOrFail();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'username' => $validated['username'] ?? null,
            'password' => $validated['password'],
            'role_id' => $role->id,
            'phone' => $validated['phone'] ?? null,
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $token = $user->createToken('auth')->plainTextToken;

        return ApiResponse::success([
            'user' => $this->userPayload($user),
            'token' => $token,
            'token_type' => 'Bearer',
        ], 'Registered successfully', 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            /** Accepts email or username (same JSON field for backward compatibility). */
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $identifier = trim($validated['email']);

        $user = User::query()
            ->with('role')
            ->where(function ($q) use ($identifier): void {
                $q->where('email', $identifier)
                    ->orWhereRaw('LOWER(username) = ?', [mb_strtolower($identifier)]);
            })
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (! $user->is_active) {
            return ApiResponse::error('Account is disabled.', 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth')->plainTextToken;

        return ApiResponse::success([
            'user' => $this->userPayload($user),
            'token' => $token,
            'token_type' => 'Bearer',
        ], 'Logged in successfully');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Logged out successfully');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('role');

        return ApiResponse::success(['user' => $this->userPayload($user)]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->password = $data['password'];
        $user->must_change_password = false;
        $user->save();

        return ApiResponse::success([
            'user' => $this->userPayload($user->fresh()->load('role')),
        ], 'Password updated');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        if ($status !== Password::RESET_LINK_SENT) {
            return ApiResponse::error(__($status), 422);
        }

        return ApiResponse::success(null, __($status));
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                    'must_change_password' => false,
                ])->save();
                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error(__($status), 422);
        }

        return ApiResponse::success(null, __($status));
    }

    private function userPayload(User $user): array
    {
        $user->loadMissing('role');
        if ($user->role?->slug === 'student') {
            $user->loadMissing('studentProfile:id,user_id');
        }
        if ($user->role?->slug === 'teacher') {
            $user->loadMissing('teacherProfile:id,user_id');
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'phone' => $user->phone,
            'avatar' => $user->avatar,
            'is_active' => $user->is_active,
            'must_change_password' => (bool) $user->must_change_password,
            'student' => $user->studentProfile
                ? ['id' => $user->studentProfile->id]
                : null,
            'teacher' => $user->teacherProfile
                ? ['id' => $user->teacherProfile->id]
                : null,
            'role' => $user->role
                ? ['id' => $user->role->id, 'name' => $user->role->name, 'slug' => $user->role->slug]
                : null,
        ];
    }
}
