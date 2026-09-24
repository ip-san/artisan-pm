<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * An array stored in a JSON column so that its key order survives every
 * database: MySQL's JSON type re-sorts object keys (by length, then
 * bytewise), which would reorder a saved query's filters. The array is
 * stored as a JSON string holding the encoded array — strings are kept
 * verbatim — and a row written before this cast (a plain JSON object or
 * array) is still read as it is.
 */
final class OrderPreservingJson implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<array-key, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode((string) $value, true);

        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
