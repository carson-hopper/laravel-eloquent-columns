<?php

declare(strict_types=1);

namespace EloquentColumn\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;

final class AsString implements CastsAttributes
{
    /**
     * Cast the given value from the database into a structured array with named sections.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Stringable
    {
        return isset($value) ? Str::of($value) : null;
    }

    /**
     * Prepare the given value for storage in the database.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return isset($value) ? (string) $value : null;
    }
}
