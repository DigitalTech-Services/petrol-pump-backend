<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Staff extends Model
{
    use HasFactory, HasAuditFields;

    public $timestamps = false;

    protected $table = 'staff';

    protected $fillable = [
        'user_id',
        'station_id',
        'name',
        'role',
        'phone',
        'join_date',
        'rate_per_day',
        'shift_hours',
        'days_worked',
        'notes',
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
        'rate_per_day' => 'float',
        'shift_hours'  => 'integer',
        'days_worked'  => 'integer',
        'join_date'    => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(StaffAdvance::class);
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(StaffAttendance::class);
    }
}
