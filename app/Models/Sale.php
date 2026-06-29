<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sale extends Model
{
    use HasFactory, HasAuditFields;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'date',
        'shift',
        'ms_volume',
        'hsd_volume',
        'speed_volume',
        'rate_ms',
        'rate_hsd',
        'rate_speed',
        'revenue',
        'cash',
        'card',
        'phone_pe',
        'credit_sale',
        'expenses',
        'balance',
        'narration',
        'created_by_id', 'created_by_name', 'created_host_name', 'created_ip',
        'updated_by_id', 'updated_by_name', 'updated_host_name', 'updated_ip',
    ];

    protected $casts = [
        'date'         => 'date:Y-m-d',
        'ms_volume'    => 'float',
        'hsd_volume'   => 'float',
        'speed_volume' => 'float',
        'rate_ms'      => 'float',
        'rate_hsd'     => 'float',
        'rate_speed'   => 'float',
        'revenue'      => 'float',
        'cash'         => 'float',
        'card'         => 'float',
        'phone_pe'     => 'float',
        'credit_sale'  => 'float',
        'expenses'     => 'float',
        'balance'      => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
