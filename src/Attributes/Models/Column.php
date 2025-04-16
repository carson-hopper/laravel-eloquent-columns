<?php

declare(strict_types=1);

namespace EloquentColumn\Attributes\Models;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Column
{
    public function __construct(
        public ?string $name = null,
        public ?string $type = 'string',
        public bool $fillable = true,
        public bool $hidden = false,
        public ?string $cast = null,

        public bool $nullable = false,
        public mixed $default = null,
        public ?int $length = null,
        public bool $index = false,
    ) {}
}
