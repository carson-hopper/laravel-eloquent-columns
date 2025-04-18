<?php

declare(strict_types=1);

namespace EloquentColumn\Attributes\Models;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Table
{
    public function __construct(public string $table, public ?string $parent = null) {}
}
