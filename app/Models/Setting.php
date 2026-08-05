<?php

namespace App\Models;

use App\Services\Portal\PortalFreshnessService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        return Cache::remember("setting.{$key}", 3600, function () use ($key, $default) {
            $setting = static::where('key', $key)->first();

            return $setting?->value ?? $default;
        });
    }

    public static function set(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting.{$key}");

        if (str_starts_with($key, 'freshness.')) {
            Cache::forget('portal.freshness.snapshot.live');
            Cache::forget(PortalFreshnessService::MODE_CACHE_KEY);
            // Presence count keys are short-lived; nothing to sweep.
        }
    }

    public static function getFloat(string $key, float $default): float
    {
        $raw = static::get($key);
        if ($raw === null || $raw === '') {
            return $default;
        }

        return (float) $raw;
    }

    public static function getInt(string $key, int $default): int
    {
        $raw = static::get($key);
        if ($raw === null || $raw === '') {
            return $default;
        }

        return (int) $raw;
    }
}