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
            'ip_address' => $this->clientIp(),
        ]);
    }

    /**
     * Plesk nginx → PHP often has REMOTE_ADDR 127.0.0.1. Prefer forwarded client IP.
     * Still record loopback when that is all we have (do not drop the audit row).
     */
    private function clientIp(): ?string
    {
        $ip = Request::ip();

        if (! $this->isLoopback($ip)) {
            return $ip;
        }

        $real = Request::header('X-Real-IP');
        if (is_string($real) && $real !== '' && ! $this->isLoopback($real)) {
            return trim($real);
        }

        $forwarded = Request::header('X-Forwarded-For');
        if (is_string($forwarded) && $forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if ($first !== '' && ! $this->isLoopback($first)) {
                return $first;
            }
        }

        return $ip;
    }

    private function isLoopback(?string $ip): bool
    {
        $ip = strtolower(trim((string) $ip));

        return $ip === '' || in_array($ip, ['127.0.0.1', '::1', 'localhost'], true);
    }
}
