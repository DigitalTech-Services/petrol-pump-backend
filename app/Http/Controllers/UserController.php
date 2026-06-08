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
        'created_by_id',
        'created_by_name',
        'updated_by_id',
        'updated_by_name',
    ];

    private function userFields(): array
    {
        return array_merge(
            ['id', 'parent_user_id', 'type', 'name', 'email', 'contact', 'created_at', 'updated_at'],
            $this->auditFields
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
                'user' => $user->only($this->userFields()),
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
            return $this->success('Profile fetched', $request->user()->only($this->userFields()));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

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

    public function storeSubUser(Request $request): JsonResponse
    {
        try {
            if ($request->user()->type === 'sub_user') {
                return $this->error('Access denied!, You cannot create other users.', 403);
            }

            $data = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'contact' => 'required|unique:users,contact',
                'password' => 'required|string|min:8',
            ]);

            $subUser = User::create([
                'parent_user_id' => $request->user()->id,
                'type' => 'sub_user',
                'name' => $data['name'],
                'email' => $data['email'],
                'contact' => $data['contact'],
                'password' => Hash::make($data['password']),
            ]);

            return $this->success('User created!', ['sub_user' => $subUser->only($this->userFields())], 201);
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
            $subUser = $request->user()->subUsers()->findOrFail($data['user_id']);

            return $this->success('Users fetched!', ['sub_user' => $subUser->only($this->userFields())]);
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
            ]);

            $subUser->update($data);

            return $this->success('User account updated!', ['sub_user' => $subUser->fresh()->only($this->userFields())]);
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
