<?php

declare(strict_types=1);

namespace EloquentColumn\Attributes\Models\Relationship;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class BelongsTo implements Relationship
{
    /**
     * @param  array<int, string>  $load
     */
    public function __construct(public ?string $foreignKey = null, public ?string $ownerKey = null, public ?string $relation = null, public bool $with = false, public array $load = []) {}
}
