<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    use ApiResponse;

    private array $userFields = [
        'id', 'parent_user_id', 'type', 'name', 'email',
        'created_at', 'created_by_id', 'created_by_name', 'created_host_name', 'created_ip',
        'updated_at', 'updated_by_id', 'updated_by_name', 'updated_host_name', 'updated_ip',
    ];

    // POST /api/admin/login
    public function login(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'username' => 'required|string',
                'password' => 'required|string',
            ]);

            $admin = Admin::where('username', $request->input('username'))->first();

            if (! $admin || ! Hash::check($request->input('password'), $admin->password)) {
                return $this->error('The provided credentials are incorrect.', 401);
            }

            $admin->tokens()->delete();

            $token = $admin->createToken('admin-token')->plainTextToken;

            return $this->success('Login successful', [
                'token' => $token,
                'admin' => ['id' => $admin->id, 'username' => $admin->username],
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // POST /api/admin/logout
    public function logout(Request $request): JsonResponse
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return $this->success('Logged out successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /api/admin/users
    public function indexUsers(): JsonResponse
    {
        try {
            $users = User::select($this->userFields)->get();

            return $this->success('Users fetched', ['users' => $users]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // POST /api/admin/users
    public function storeUser(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'name'     => 'required|string|max:255',
                'email'    => 'required|email|unique:users,email',
                'password' => 'required|string|min:8',
            ]);

            $user = User::create([
                'type'     => 'user',
                'name'     => $data['name'],
                'email'    => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            return $this->success('User created', ['user' => $user->only($this->userFields)], 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // GET /api/admin/users/{id}
    public function showUser(int $id): JsonResponse
    {
        try {
            $user = User::findOrFail($id);

            return $this->success('User fetched', ['user' => $user->only($this->userFields)]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // PUT /api/admin/users/{id}
    public function updateUser(Request $request, int $id): JsonResponse
    {
        try {
            $user = User::findOrFail($id);

            $data = $request->validate([
                'name'     => 'sometimes|string|max:255',
                'email'    => "sometimes|email|unique:users,email,{$user->id}",
                'password' => 'sometimes|string|min:8',
            ]);

            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }

            $user->update($data);

            return $this->success('User updated', ['user' => $user->fresh()->only($this->userFields)]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // DELETE /api/admin/users/{id}
    public function destroyUser(int $id): JsonResponse
    {
        try {
            $user = User::findOrFail($id);
            $user->delete();

            return $this->success('User deleted');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
