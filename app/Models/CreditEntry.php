<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditEntry extends Model
{
    use HasFactory, HasAuditFields;

    public $timestamps = false;

    protected $table = 'credit_entries';

    public const FUEL_TYPES    = ['ms', 'hsd', 'speed'];
    public const PAYMENT_MODES = ['Cash', 'PhonePe', 'Card', 'Bank Transfer', 'Cheque'];

    protected $fillable = [
        'user_id',
        'station_id',
        'credit_customer_id',
        'date',
        'fuel_type',
        'litres',
        'rate',
        'amount',
        'vehicle_number',
        'notes',
        'status',
        'paid_on',
        'payment_mode',
        'payment_ref',
        'created_by_id', 'created_by_name', 'created_host_name', 'created_ip',
        'updated_by_id', 'updated_by_name', 'updated_host_name', 'updated_ip',
    ];

    protected $casts = [
        'date'    => 'date:Y-m-d',
        'paid_on' => 'date:Y-m-d',
        'litres'  => 'float',
        'rate'    => 'float',
        'amount'  => 'float',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CreditCustomer::class, 'credit_customer_id');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
