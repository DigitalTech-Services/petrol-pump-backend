<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockEntry extends Model
{
    use HasFactory, HasAuditFields;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'station_id',
        'date',
        'fuel_type',
        'opening',
        'received',
        'closing',
        'actual_sale',
        'remarks',
        'created_by_id', 'created_by_name', 'created_host_name', 'created_ip',
        'updated_by_id', 'updated_by_name', 'updated_host_name', 'updated_ip',
    ];

    protected $casts = [
        'date'        => 'date:Y-m-d',
        'opening'     => 'float',
        'received'    => 'float',
        'closing'     => 'float',
        'actual_sale' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
