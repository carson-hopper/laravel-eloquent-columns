<?php

declare(strict_types=1);

namespace EloquentColumn\Attributes\Models;

use Attribute;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_PROPERTY)]
final class ValidationRule
{
    public function __construct(public string $rule, public ?string $message = null) {}
}
