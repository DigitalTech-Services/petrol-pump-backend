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

    // Starter rates for a station that has never saved Settings → Fuel Rates —
    // used both to seed a new station's real rows and as the fallback a station
    // without any saved row should be treated as (see DashboardController's
    // profit/loss calc), so it matches what Settings itself already displays.
    public static function defaults(): array
    {
        return [
            ['fuel_key' => 'ms',    'name' => 'MS Petrol',  'abbr' => 'MS',  'type' => 'Motor Spirit',      'rate' => 104.77, 'actual_rate' => 98.50,  'effective_date' => '2026-04-01', 'color' => '#f59e0b'],
            ['fuel_key' => 'hsd',   'name' => 'HSD Diesel', 'abbr' => 'HSD', 'type' => 'High Speed Diesel', 'rate' => 91.28,  'actual_rate' => 86.00,  'effective_date' => '2026-04-01', 'color' => '#10b981'],
            ['fuel_key' => 'speed', 'name' => 'Speed',      'abbr' => 'SP',  'type' => 'Premium Petrol',    'rate' => 113.85, 'actual_rate' => 106.50, 'effective_date' => '2026-04-01', 'color' => '#3b82f6'],
        ];
    }
}
