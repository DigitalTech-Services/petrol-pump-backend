<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    use HasFactory, HasAuditFields;

    protected $table = 'notification_preferences';

    protected $fillable = [
        'user_id',
        'notif_key',
        'icon',
        'label',
        'sub',
        'enabled',
        'created_by_id', 'created_by_name', 'created_host_name', 'created_ip',
        'updated_by_id', 'updated_by_name', 'updated_host_name', 'updated_ip',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
