<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A generic, admin-editable key/value settings store. Reads are cached
 * indefinitely (including a not-yet-set key's default) and invalidated on
 * write, since settings are read on nearly every request but change rarely.
 */
#[Fillable(['key', 'value'])]
final class Setting extends Model
{
    protected $primaryKey = 'key';

    private const string CACHE_KEY = 'settings:all';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /**
     * The whole table is read once and memoized for the request (and kept in the cache store),
     * because a page reads dozens of settings: the settings form alone read ~100 keys, and with
     * one lookup per key each was its own query. Writes drop the memo so the next read is fresh.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $values = Cache::memo()->rememberForever(self::CACHE_KEY, fn () => self::query()->get()
            ->mapWithKeys(fn (self $setting) => [$setting->key => $setting->value])
            ->all());

        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        self::forget();
    }

    /**
     * Drops the memoized table, for code that writes settings rows without going through set().
     */
    public static function forget(): void
    {
        Cache::memo()->forget(self::CACHE_KEY);
    }
}
