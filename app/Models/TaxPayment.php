<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxPayment extends Model
{
    use HasFactory, HasAuditFields;

    public $timestamps = false;

    protected $table = 'tax_payments';

    // Fuel is billed under state VAT; GST applies to lubricants/services.
    public const TAX_TYPES = [
        'GST',
        'VAT',
        'TDS',
        'TCS',
        'Advance Tax',
        'Income Tax',
        'Professional Tax',
        'Other',
    ];

    public const PAYMENT_MODES = ['Net Banking', 'NEFT / RTGS', 'UPI', 'Cheque', 'Cash', 'Other'];

    protected $fillable = [
        'user_id',
        'station_id',
        'date',
        'tax_type',
        'period',
        'challan_no',
        'amount',
        'interest',
        'penalty',
        'payment_mode',
        'narration',
        'created_by_id', 'created_by_name', 'created_host_name', 'created_ip',
        'updated_by_id', 'updated_by_name', 'updated_host_name', 'updated_ip',
    ];

    protected $casts = [
        'date'     => 'date:Y-m-d',
        'amount'   => 'float',
        'interest' => 'float',
        'penalty'  => 'float',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
