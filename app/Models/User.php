<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasAuditFields;

    protected $fillable = [
        'parent_user_id',
        'type',
        'business_name',
        'name',
        'email',
        'contact',
        'password',
        'created_by_id',
        'created_by_name',
        'created_host_name',
        'created_ip',
        'updated_by_id',
        'updated_by_name',
        'updated_host_name',
        'updated_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    public function subUsers(): HasMany
    {
        return $this->hasMany(User::class, 'parent_user_id');
    }

    /**
     * User IDs whose data this account may view in aggregate.
     * A manager (sub_user) only ever sees their own individual records.
     * An owner sees their own account plus every manager they created,
     * combined — this is what powers the owner's "all stats" view.
     */
    public function scopeUserIds(): array
    {
        if ($this->type === 'sub_user') {
            return [$this->id];
        }

        return array_merge([$this->id], $this->subUsers()->pluck('id')->all());
    }

    /**
     * The business name shown in navigation. Owners have their own; a manager
     * inherits the business name of the owner who created them.
     */
    public function resolveBusinessName(): ?string
    {
        return $this->type === 'sub_user'
            ? $this->parent?->business_name
            : $this->business_name;
    }
}
