<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankCharge extends Model
{
    use HasFactory, HasAuditFields;

    public $timestamps = false;

    protected $table = 'bank_charges';

    public const CHARGE_TYPES = [
        'POS / MDR Charges',
        'NEFT / RTGS / IMPS',
        'SMS Charges',
        'Cheque Book',
        'Cheque Bounce',
        'Cash Handling',
        'Annual Maintenance',
        'Minimum Balance Penalty',
        'Loan / OD Interest',
        'Processing Fee',
        'Other',
    ];

    protected $fillable = [
        'user_id',
        'station_id',
        'date',
        'bank_name',
        'account_no',
        'charge_type',
        'amount',
        'gst_amount',
        'reference_no',
        'narration',
        'created_by_id', 'created_by_name', 'created_host_name', 'created_ip',
        'updated_by_id', 'updated_by_name', 'updated_host_name', 'updated_ip',
    ];

    protected $casts = [
        'date'       => 'date:Y-m-d',
        'amount'     => 'float',
        'gst_amount' => 'float',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
