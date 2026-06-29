<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeterReading extends Model
{
    use HasFactory, HasAuditFields;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'date',
        'total_used',
        'notes',
        'created_by_id', 'created_by_name', 'created_host_name', 'created_ip',
        'updated_by_id', 'updated_by_name', 'updated_host_name', 'updated_ip',
    ];

    protected $casts = [
        'date'       => 'date:Y-m-d',
        'total_used' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function nozzleReadings(): HasMany
    {
        return $this->hasMany(MeterReadingNozzle::class)->orderBy('nozzle_id');
    }
}
