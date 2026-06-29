<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeterReadingNozzle extends Model
{
    protected $fillable = [
        'meter_reading_id',
        'nozzle_id',
        'opening',
        'closing',
        'used',
    ];

    protected $casts = [
        'opening' => 'float',
        'closing' => 'float',
        'used'    => 'float',
    ];

    public function meterReading(): BelongsTo
    {
        return $this->belongsTo(MeterReading::class);
    }
}
