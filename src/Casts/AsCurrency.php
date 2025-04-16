<?php

declare(strict_types=1);

namespace EloquentColumn\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

final class AsCurrency implements CastsAttributes
{
    /**
     * Cast the given value from the database into a structured array with named sections.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): float
    {
        return $value / 100;
    }

    /**
     * Prepare the given value for storage in the database.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return (int) round(($value ?? 0) * 100);
    }
}
