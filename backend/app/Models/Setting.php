<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-managed key/value configuration — values that belong to the business
 * rather than to a deploy, such as where the accounts department's reports go.
 * A missing row falls back to the caller's default, which is normally the
 * matching config() value, so an unconfigured install keeps working.
 */
class Setting extends Model
{
    public const ACCOUNTS_EMAIL = 'stock_count.accounts_email';

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'json'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::rememberForever(
            "setting:{$key}",
            fn () => static::where('key', $key)->value('value'),
        );

        return $value ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting:{$key}");
    }

    /** Accounts department addresses the variance report is mailed to. */
    public static function accountsEmails(): array
    {
        $raw = static::get(self::ACCOUNTS_EMAIL, config('surgical.notifications.accounts'));

        $list = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw);

        return array_values(array_unique(array_filter(
            array_map('trim', $list),
            fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL),
        )));
    }
}
