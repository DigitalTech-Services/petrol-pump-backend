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
        'id',
        'parent_user_id',
        'type',
        'name',
        'email',
        'contact',
        'created_at',
        'created_by_id',
        'created_by_name',
        'updated_at',
        'updated_by_id',
        'updated_by_name',
    ];

    public function login(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'username' => 'required|string',
                'password' => 'required|string',
            ]);

            $admin = Admin::where('username', $request->input('username'))->first();

            if (!$admin || !Hash::check($request->input('password'), $admin->password)) {
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

    public function logout(Request $request): JsonResponse
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return $this->success('Logged out successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function indexUsers(): JsonResponse
    {
        try {
            $users = User::select($this->userFields)->get();

            return $this->success('Users fetched', ['users' => $users]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function storeUser(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'contact' => 'required|unique:users,contact',
                'password' => 'required|string|min:8',
            ]);

            $user = User::create([
                'type' => 'user',
                'name' => $data['name'],
                'email' => $data['email'],
                'contact' => $data['contact'],
                'password' => Hash::make($data['password']),
            ]);

            return $this->success('User created', ['user' => $user->only($this->userFields)], 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function showUser(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'user_id' => 'required|int',
            ]);
            $user = User::findOrFail($data['user_id']);

            return $this->success('User fetched', ['user' => $user->only($this->userFields)]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function updateUser(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'user_id' => 'required|int',
            ]);

            $user = User::findOrFail($data['user_id']);

            $data = $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => "sometimes|email|unique:users,email,{$user->id}",
            ]);

            $user->update($data);

            return $this->success('User updated', ['user' => $user->fresh()->only($this->userFields)]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroyUser(Request $request): JsonResponse
    {
        try {
           $data = $request->validate([
                'user_id' => 'required|int',
            ]);

            $user = User::findOrFail($data['user_id']);
            $user->delete();

            return $this->success('User deleted');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
