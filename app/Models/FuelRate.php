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
        'effective_date',
        'color',
        'created_by_id', 'created_by_name', 'created_host_name', 'created_ip',
        'updated_by_id', 'updated_by_name', 'updated_host_name', 'updated_ip',
    ];

    protected $casts = [
        'rate'           => 'float',
        'effective_date' => 'date:Y-m-d',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
