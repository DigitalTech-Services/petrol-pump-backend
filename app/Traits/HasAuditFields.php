<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

trait HasAuditFields
{
    public static function bootHasAuditFields(): void
    {
        static::creating(function ($model) {
            $user = Auth::user();

            $model->created_by_id     = $user?->id;
            $model->created_by_name   = $user?->name ?? $user?->username ?? null;
            $model->created_host_name = gethostname() ?: null;
            $model->created_ip        = Request::ip();

            $model->updated_by_id     = $user?->id;
            $model->updated_by_name   = $user?->name ?? $user?->username ?? null;
            $model->updated_host_name = gethostname() ?: null;
            $model->updated_ip        = Request::ip();
        });

        static::updating(function ($model) {
            $user = Auth::user();

            $model->updated_by_id        = $user?->id;
            $model->updated_by_name      = $user?->name ?? $user?->username ?? null;
            $model->updated_host_name    = gethostname() ?: null;
            $model->updated_ip           = Request::ip();
        });
    }
}
