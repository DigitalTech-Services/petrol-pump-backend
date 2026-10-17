<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditCustomer extends Model
{
    use HasFactory, HasAuditFields;

    public $timestamps = false;

    protected $table = 'credit_customers';

    protected $fillable = [
        'user_id',
        'station_id',
        'name',
        'mobile',
        'vehicle_number',
        'address',
        'credit_limit',
        'notes',
        'created_by_id', 'created_by_name', 'created_host_name', 'created_ip',
        'updated_by_id', 'updated_by_name', 'updated_host_name', 'updated_ip',
    ];

    protected $casts = [
        'credit_limit' => 'float',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(CreditEntry::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
