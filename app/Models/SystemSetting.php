<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable(['key', 'value'])]
class SystemSetting extends Model
{
    public static function get(string $key, $default = null): mixed
    {
        return Cache::remember("system_setting:{$key}", 3600, function () use ($key, $default) {
            $setting = static::where('key', $key)->first();

            return $setting ? $setting->value : $default;
        });
    }

    public static function set(string $key, mixed $value): static
    {
        $setting = static::updateOrCreate(
            ['key' => $key],
            ['value' => is_scalar($value) || is_null($value) ? (string) $value : json_encode($value)]
        );

        Cache::forget("system_setting:{$key}");

        return $setting;
    }
}
