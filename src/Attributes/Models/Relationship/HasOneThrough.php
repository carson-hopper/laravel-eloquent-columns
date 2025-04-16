<?php

declare(strict_types=1);

namespace EloquentColumn\Attributes\Models\Relationship;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class HasOneThrough implements Relationship
{
    /**
     * @param  array<int, string>  $load
     */
    public function __construct(public string $through, public ?string $firstKey = null, public ?string $secondKey = null, public ?string $localKey = null, public ?string $secondLocalKey = null, public bool $with = false, public array $load = []) {}
}
