<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    use ApiResponse;

    private array $auditFields = [
        'created_by_id', 'created_by_name', 'created_host_name', 'created_ip',
        'updated_by_id', 'updated_by_name', 'updated_host_name', 'updated_ip',
    ];

    private function userFields(): array
    {
        return array_merge(
            ['id', 'parent_user_id', 'type', 'name', 'email', 'created_at', 'updated_at'],
            $this->auditFields
        );
    }

    // POST /api/user/login  (works for both user and sub_user)
    public function login(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email'    => 'required|email',
                'password' => 'required|string',
            ]);

            $user = User::where('email', $request->email)->first();

            if (! $user || ! Hash::check($request->password, $user->password)) {
                return $this->error('The provided credentials are incorrect.', 401);
            }

            $user->tokens()->delete();

            $token = $user->createToken('user-token')->plainTextToken;

            return $this->success('Login successful', [
                'token' => $token,
                'user'  => $user->only($this->userFields()),
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // POST /api/user/logout
    public function logout(Request $request): JsonResponse
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return $this->success('Logged out successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /api/user/profile
    public function profile(Request $request): JsonResponse
    {
        try {
            return $this->success('Profile fetched', $request->user()->only($this->userFields()));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /api/user/sub-users
    public function indexSubUsers(Request $request): JsonResponse
    {
        try {
            $subUsers = $request->user()
                ->subUsers()
                ->select($this->userFields())
                ->get();

            return $this->success('Sub-users fetched', ['sub_users' => $subUsers]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // POST /api/user/sub-users  (only type=user can create sub-users)
    public function storeSubUser(Request $request): JsonResponse
    {
        try {
            if ($request->user()->type === 'sub_user') {
                return $this->error('Sub-users cannot create other sub-users.', 403);
            }

            $data = $request->validate([
                'name'     => 'required|string|max:255',
                'email'    => 'required|email|unique:users,email',
                'password' => 'required|string|min:8',
            ]);

            $subUser = User::create([
                'parent_user_id' => $request->user()->id,
                'type'           => 'sub_user',
                'name'           => $data['name'],
                'email'          => $data['email'],
                'password'       => Hash::make($data['password']),
            ]);

            return $this->success('Sub-user created', ['sub_user' => $subUser->only($this->userFields())], 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /api/user/sub-users/{id}
    public function showSubUser(Request $request, int $id): JsonResponse
    {
        try {
            $subUser = $request->user()->subUsers()->findOrFail($id);

            return $this->success('Sub-user fetched', ['sub_user' => $subUser->only($this->userFields())]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // PUT /api/user/sub-users/{id}
    public function updateSubUser(Request $request, int $id): JsonResponse
    {
        try {
            $subUser = $request->user()->subUsers()->findOrFail($id);

            $data = $request->validate([
                'name'     => 'sometimes|string|max:255',
                'email'    => "sometimes|email|unique:users,email,{$subUser->id}",
                'password' => 'sometimes|string|min:8',
            ]);

            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }

            $subUser->update($data);

            return $this->success('Sub-user updated', ['sub_user' => $subUser->fresh()->only($this->userFields())]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // DELETE /api/user/sub-users/{id}
    public function destroySubUser(Request $request, int $id): JsonResponse
    {
        try {
            $subUser = $request->user()->subUsers()->findOrFail($id);
            $subUser->delete();

            return $this->success('Sub-user deleted');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
