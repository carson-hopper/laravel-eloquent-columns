<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;

if (! function_exists('mid')) {
    function mid(Model|string $model, ?string $column = null): string
    {
        if (is_string($model)) {
            $model = new $model;
        }

        return $column !== null && $column !== '' && $column !== '0' ? $column : $model->getForeignKey();
    }
}
