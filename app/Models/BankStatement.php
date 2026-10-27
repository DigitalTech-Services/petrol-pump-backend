<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatement extends Model
{
    use HasFactory, HasAuditFields;

    public $timestamps = false;

    protected $table = 'bank_statements';

    protected $fillable = [
        'user_id',
        'station_id',
        'bank_name',
        'account_no',
        'statement_month',
        'opening_balance',
        'total_credits',
        'total_debits',
        'closing_balance',
        'statement_url',
        'shared_with_ca',
        'shared_on',
        'notes',
        'created_by_id', 'created_by_name', 'created_host_name', 'created_ip',
        'updated_by_id', 'updated_by_name', 'updated_host_name', 'updated_ip',
    ];

    protected $casts = [
        'opening_balance' => 'float',
        'total_credits'   => 'float',
        'total_debits'    => 'float',
        'closing_balance' => 'float',
        'shared_with_ca'  => 'boolean',
        'shared_on'       => 'date:Y-m-d',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
