<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

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

    /**
     * Turn a caught exception into a response that says what actually went wrong,
     * with the right status code — instead of a blanket 500 with the raw message.
     */
    protected function exceptionError(Throwable $e): JsonResponse
    {
        // Missing/invalid fields — first message for the toast, full list for forms
        if ($e instanceof ValidationException) {
            return $this->error(
                collect($e->errors())->flatten()->first() ?? 'Invalid data.',
                422,
                ['errors' => $e->errors()]
            );
        }

        // findOrFail() — "Staff not found.", "Credit customer not found."
        if ($e instanceof ModelNotFoundException) {
            $model = Str::of(class_basename($e->getModel()))->snake(' ')->ucfirst();
            return $this->error("{$model} not found. It may have been deleted — refresh the page.", 404);
        }

        if ($e instanceof QueryException) {
            return $this->queryError($e);
        }

        Log::error($e);

        return $this->error($e->getMessage() ?: 'Something went wrong on the server.', 500);
    }

    // Database errors carry raw SQL — map the common constraint failures to plain
    // language and log the rest, so users see the cause and not the query.
    private function queryError(QueryException $e): JsonResponse
    {
        $code = $e->errorInfo[1] ?? null;   // MySQL driver error code

        switch ($code) {
            case 1062:  // Duplicate entry
                return $this->error('This record already exists — a duplicate value was entered.', 409);
            case 1451:  // Cannot delete or update a parent row
                return $this->error('This record is used by other entries. Delete or reassign those first.', 409);
            case 1452:  // Cannot add or update a child row (FK target missing)
                return $this->error('A linked record does not exist. Create it first, then try again.', 422);
            case 1048:  // Column cannot be null
            case 1364:  // Field doesn't have a default value
                $column = preg_match("/'([^']+)'/", $e->errorInfo[2] ?? '', $m) ? str_replace('_', ' ', $m[1]) : 'A required field';
                return $this->error(ucfirst($column) . ' is required.', 422);
        }

        Log::error($e);

        return $this->error(
            config('app.debug') ? $e->getMessage() : 'A database error occurred (code ' . ($code ?? 'unknown') . '). Please try again or contact support.',
            500
        );
    }
}
