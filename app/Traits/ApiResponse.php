<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'status'  => $status,
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    protected function error(string $message, int $status = 500, mixed $data = null): JsonResponse
    {
        return response()->json([
            'status'  => $status,
            'success' => false,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }
}
