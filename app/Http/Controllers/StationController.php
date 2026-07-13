<?php

namespace App\Http\Controllers;

use App\Models\Station;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationController extends Controller
{
    use ApiResponse;

    // Owner-only route (see role:user middleware) — an owner only ever manages their own stations.
    private function ownerScope(Request $request)
    {
        return Station::where('user_id', $request->user()->id);
    }

    private function present(Station $station): array
    {
        return array_merge(
            $station->only(['id', 'user_id', 'name', 'dealer_code', 'address', 'city', 'state', 'gst', 'pan', 'phone']),
            [
                'manager' => $station->currentManager()->first()?->only(['id', 'name', 'email']),
            ]
        );
    }

    // GET /stations
    public function index(Request $request): JsonResponse
    {
        try {
            $stations = $this->ownerScope($request)->get()->map(fn($s) => $this->present($s));

            return $this->success('Stations fetched.', ['stations' => $stations->values()]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // POST /stations
    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'name'        => 'required|string|max:255',
                'dealer_code' => 'sometimes|nullable|string|max:255',
                'address'     => 'sometimes|nullable|string',
                'city'        => 'sometimes|nullable|string|max:100',
                'state'       => 'sometimes|nullable|string|max:100',
                'gst'         => 'sometimes|nullable|string|max:20',
                'pan'         => 'sometimes|nullable|string|max:20',
                'phone'       => 'sometimes|nullable|string|max:20',
            ]);

            $station = Station::create(array_merge($data, ['user_id' => $request->user()->id]));

            return $this->success('Station created.', ['station' => $this->present($station)], 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // PUT /stations/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $station = $this->ownerScope($request)->findOrFail($id);

            $data = $request->validate([
                'name'        => 'sometimes|string|max:255',
                'dealer_code' => 'sometimes|nullable|string|max:255',
                'address'     => 'sometimes|nullable|string',
                'city'        => 'sometimes|nullable|string|max:100',
                'state'       => 'sometimes|nullable|string|max:100',
                'gst'         => 'sometimes|nullable|string|max:20',
                'pan'         => 'sometimes|nullable|string|max:20',
                'phone'       => 'sometimes|nullable|string|max:20',
            ]);

            $station->update($data);

            return $this->success('Station updated.', ['station' => $this->present($station->fresh())]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // DELETE /stations/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $station = $this->ownerScope($request)->findOrFail($id);

            if ($station->currentManager()->exists()) {
                return $this->error('Unassign this station\'s manager before deleting it.', 422);
            }

            $station->delete();

            return $this->success('Station deleted.');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
