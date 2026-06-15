<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class ActivityLogService
{
    public function log(
        string $action,
        ?Model $subject = null,
        ?array $properties = null,
        ?int $clientId = null,
    ): ActivityLog {
        return ActivityLog::create([
            'user_id' => Auth::id(),
            'client_id' => $clientId ?? Auth::user()?->client_id,
            'action' => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->getKey(),
            'properties' => $properties,
            'ip_address' => Request::ip(),
        ]);
    }
}
