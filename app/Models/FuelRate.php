<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuelRate extends Model
{
    use HasFactory, HasAuditFields;

    protected $fillable = [
        'station_id',
        'fuel_key',
        'name',
        'abbr',
        'type',
        'rate',
        'actual_rate',
        'effective_date',
        'color',
        'created_by_id', 'created_by_name', 'created_host_name', 'created_ip',
        'updated_by_id', 'updated_by_name', 'updated_host_name', 'updated_ip',
    ];

    protected $casts = [
        'rate'           => 'float',
        'actual_rate'    => 'float',
        'effective_date' => 'date:Y-m-d',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    // The rate in effect for a station+fuel on a specific date (defaults to
    // today) — the latest saved row whose effective_date is on or before that
    // date. Returns null if the station has never saved a rate for that fuel
    // on or before that date — callers must treat that as "not configured yet",
    // never fabricate a number, since a stale rate is actively misleading.
    public static function effectiveFor(int $stationId, string $fuelKey, ?string $date = null): ?self
    {
        return static::where('station_id', $stationId)
            ->where('fuel_key', $fuelKey)
            ->whereDate('effective_date', '<=', $date ?? now()->format('Y-m-d'))
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();
    }
}
