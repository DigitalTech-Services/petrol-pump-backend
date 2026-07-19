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
        'monthly_salary',
        'shift_hours',
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
        'monthly_salary' => 'float',
        'shift_hours'    => 'integer',
        'join_date'      => 'date',
    ];

    // Hourly equivalent of the monthly salary, recomputed from today's calendar
    // days so it stays accurate as months change length (28-31 days) — never
    // cached or stored, so it's always current at the moment it's read.
    public function currentRatePerHour(): float
    {
        if ($this->shift_hours <= 0) {
            return 0.0;
        }

        return round($this->monthly_salary / now()->daysInMonth / $this->shift_hours, 2);
    }

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
