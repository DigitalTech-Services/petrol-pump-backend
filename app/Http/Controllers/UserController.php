<?php

namespace App\Http\Controllers;

use App\Models\Station;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    use ApiResponse;

    private array $auditFields = [
        'created_by_id',
        'created_by_name',
        'updated_by_id',
        'updated_by_name',
    ];

    private function userFields(): array
    {
        return array_merge(
            ['id', 'parent_user_id', 'station_id', 'type', 'name', 'email', 'contact', 'created_at', 'updated_at'],
            $this->auditFields
        );
    }

    // Validates that $stationId belongs to $owner, returning the Station or failing with a 404.
    private function ownedStation(User $owner, int $stationId): Station
    {
        return Station::where('user_id', $owner->id)->findOrFail($stationId);
    }

    // Owner has their own business_name; a manager inherits the owner's.
    private function presentUser(User $user): array
    {
        return array_merge(
            $user->only($this->userFields()),
            ['business_name' => $user->resolveBusinessName()]
        );
    }

    // Includes the assigned station's name alongside the manager's fields.
    private function presentSubUser(User $subUser): array
    {
        return array_merge(
            $subUser->only($this->userFields()),
            [
                'business_name' => $subUser->resolveBusinessName(),
                'station' => $subUser->station?->only(['id', 'name']),
            ]
        );
    }

    public function login(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required|string',
            ]);

            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return $this->error('The provided credentials are incorrect.', 401);
            }

            $user->tokens()->delete();

            $token = $user->createToken('user-token')->plainTextToken;

            return $this->success('Login successful', [
                'token' => $token,
                'user' => $user->type === 'sub_user' ? $this->presentSubUser($user) : $this->presentUser($user),
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return $this->success('Logged out successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function profile(Request $request): JsonResponse
    {
        try {
            return $this->success('Profile fetched!', $this->presentUser($request->user()));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // PUT /user/profile — update own name/email; business_name is owner-only
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $data = $request->validate([
                'name'          => 'sometimes|string|max:255',
                'business_name' => 'sometimes|string|max:255',
                'email'         => "sometimes|email|unique:users,email,{$user->id}",
            ]);

            if (isset($data['business_name']) && $user->type !== 'user') {
                return $this->error('Only the business owner can update the business name.', 403);
            }

            $user->update($data);

            return $this->success('Profile updated!', $this->presentUser($user->fresh()));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function indexSubUsers(Request $request): JsonResponse
    {
        try {
            $subUsers = $request->user()
                ->subUsers()
                ->with('station:id,name')
                ->select($this->userFields())
                ->get();

            return $this->success('Users fetched!', ['sub_users' => $subUsers]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function storeSubUser(Request $request): JsonResponse
    {
        try {
            if ($request->user()->type !== 'user') {
                return $this->error('Access denied! Only the main user can create managers.', 403);
            }

            $data = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                // Column is varchar(10) — validate format/length here so a bad
                // number gets a clean error instead of a raw DB truncation error.
                'contact' => ['required', 'string', 'max:10', 'regex:/^[6-9][0-9]{9}$/', 'unique:users,contact'],
                'password' => 'required|string|min:8',
                'station_id' => 'sometimes|nullable|integer',
            ]);

            if (!empty($data['station_id'])) {
                $this->ownedStation($request->user(), $data['station_id']);

                // A station only ever has one active manager — bumping a new manager
                // onto it unassigns whoever was previously running it.
                User::where('station_id', $data['station_id'])->update(['station_id' => null]);
            }

            $subUser = User::create([
                'parent_user_id' => $request->user()->id,
                'station_id' => $data['station_id'] ?? null,
                'type' => 'sub_user',
                'name' => $data['name'],
                'email' => $data['email'],
                'contact' => $data['contact'],
                'password' => Hash::make($data['password']),
            ]);

            return $this->success('User created!', ['sub_user' => $this->presentSubUser($subUser)], 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function showSubUser(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'user_id' => 'required|int',
            ]);
            $subUser = $request->user()->subUsers()->with('station:id,name')->findOrFail($data['user_id']);

            return $this->success('Users fetched!', ['sub_user' => $this->presentSubUser($subUser)]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function updateSubUser(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'user_id' => 'required|int',
            ]);
            $subUser = $request->user()->subUsers()->findOrFail($data['user_id']);

            $data = $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => "sometimes|email|unique:users,email,{$subUser->id}",
                'station_id' => 'sometimes|nullable|integer',
            ]);

            if (array_key_exists('station_id', $data) && $data['station_id'] !== null) {
                $this->ownedStation($request->user(), $data['station_id']);

                // A station only ever has one active manager — bumping this manager
                // onto it unassigns whoever else was previously running it.
                User::where('station_id', $data['station_id'])
                    ->where('id', '!=', $subUser->id)
                    ->update(['station_id' => null]);
            }

            $subUser->update($data);

            return $this->success('User account updated!', ['sub_user' => $this->presentSubUser($subUser->fresh('station'))]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroySubUser(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'user_id' => 'required|int',
            ]);
            $subUser = $request->user()->subUsers()->findOrFail($data['user_id']);
            $subUser->delete();

            return $this->success('User Account deleted!');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
