<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use HasFactory, HasAuditFields;

    public $timestamps = false;

    protected $table = 'expenses';

    protected $fillable = [
        'user_id',
        'date',
        'amount',
        'category',
        'narration',
        'paid_by',
        'created_by_id',
        'created_by_name',
        'created_host_name',
        'created_ip',
        'updated_by_id',
        'updated_by_name',
        'updated_host_name',
        'updated_ip',
    ];

    protected $casts = [
        'amount' => 'float',
        'date'   => 'date:Y-m-d',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
