<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StationSetting extends Model
{
    use HasFactory, HasAuditFields;

    protected $fillable = [
        'user_id',
        'station_name',
        'dealer_code',
        'owner_name',
        'phone',
        'address',
        'city',
        'state',
        'gst',
        'pan',
        'created_by_id', 'created_by_name', 'created_host_name', 'created_ip',
        'updated_by_id', 'updated_by_name', 'updated_host_name', 'updated_ip',
    ];
}
