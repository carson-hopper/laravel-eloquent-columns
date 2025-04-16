<?php

declare(strict_types=1);

namespace EloquentColumn\Traits\Models;

use EloquentColumn\Attributes\Models\Append;
use EloquentColumn\Attributes\Models\Column;
use EloquentColumn\Attributes\Models\Relationship\BelongsTo;
use EloquentColumn\Attributes\Models\Relationship\HasMany;
use EloquentColumn\Attributes\Models\Relationship\HasManyThrough;
use EloquentColumn\Attributes\Models\Relationship\HasOne;
use EloquentColumn\Attributes\Models\Relationship\HasOneThrough;
use EloquentColumn\Attributes\Models\Relationship\Relationship;
use EloquentColumn\Attributes\Models\Table;
use EloquentColumn\Attributes\Models\ValidationRule;
use EloquentColumn\Models\ModelBase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionProperty;

trait HasColumnAttributes
{
    private array $dynamicRelations = [];

    // /////////////////////////////////

    public function __get($key)
    {
        if (isset($this->dynamicRelations[$key])) {
            return $this->load($key)->getAttribute($key);
        }

        return parent::__get($key);
    }

    public function __call($method, $parameters)
    {
        if (isset($this->dynamicRelations[$method])) {
            return call_user_func($this->dynamicRelations[$method]);
        }

        return parent::__call($method, $parameters);
    }

    public static function __callStatic($method, $parameters)
    {
        if ($method === 'create' && count($parameters) >= 1) {
            foreach ($parameters[0] as $key => $parameter) {
                if (class_exists($key)) {
                    $instance = new $key;
                    if ($instance instanceof ModelBase) {
                        unset($parameters[0][$key]);
                        $parameters[0][mid($instance)] = $parameter;
                    }
                }
            }
        }

        return parent::__callStatic($method, $parameters);
    }

    // /////////////////////////////////

    public static function getTableName(): string
    {
        $reflection = new ReflectionClass(static::class);

        foreach ($reflection->getAttributes() as $attribute) {
            if ($attribute->getName() === Table::class) {
                return $attribute->newInstance()->table;
            }
        }

        return Str::snake(Str::pluralStudly($reflection->getShortName()));
    }

    public static function getColumnDefinitions(): Collection
    {
        $definitions = [];
        $reflection = new ReflectionClass(static::class);

        foreach ($reflection->getProperties() as $property) {
            foreach ($property->getAttributes(Column::class) as $attribute) {
                /** @var Column $column */
                $column = $attribute->newInstance();

                $name = $column->name ?? Str::snake($property->getName());
                // @phpstan-ignore method.notFound
                $propertyType = $property->getType()->getName();
                if (class_exists($propertyType)) {
                    $instance = new $propertyType();
                    if ($instance instanceof ModelBase) {
                        $name = $column->name ?? mid($instance);
                        $column->type = 'integer';
                    }
                }

                $definitions[$name] = [
                    'column' => $column,
                    'property' => $property,
                ];
            }
        }

        return collect($definitions);
    }

    public static function getValidationRules(): array
    {
        $rules = [];

        $reflection = new ReflectionClass(static::class);
        foreach ($reflection->getProperties() as $property) {
            foreach ($property->getAttributes(Column::class) as $column) {
                $name = $column->newInstance()->name ?? $property->getName();
                foreach ($property->getAttributes(ValidationRule::class) as $attribute) {
                    $instance = $attribute->newInstance();
                    $rules[$name][$instance->rule] = $instance->message;
                }
            }
        }

        return $rules;
    }

    public function initializeHasColumnAttributes(): void
    {
        $this->table = $this->getTableName();

        foreach ($this->getColumnDefinitions() as $name => $definition) {
            /** @var ReflectionProperty $property */
            $property = $definition['property'];

            /** @var Column $column */
            $column = $definition['column'];

            if ($column->fillable) {
                $this->fillable[] = $name;
            }

            if ($column->hidden) {
                $this->hidden[] = $name;
            }

            if ($column->cast) {
                $this->casts[$name] = $column->cast;
            }
        }

        $reflection = new ReflectionClass($this);
        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes() as $attribute) {
                if ($attribute->getName() === Append::class) {
                    // @phpstan-ignore method.notFound
                    $this->appends[] = Str::snake($method->getName());
                }
            }
        }

        foreach ($reflection->getProperties() as $property) {
            foreach ($property->getAttributes() as $attribute) {
                // @phpstan-ignore method.notFound
                $propertyName = $property->getName();
                // @phpstan-ignore method.notFound
                $propertyTypeName = $property->getType()->getName();

                $instance = $attribute->newInstance();

                if ($instance instanceof BelongsTo) {
                    $this->fillable[] = mid($propertyTypeName);
                    $this->hidden[] = mid($propertyTypeName);

                    $foreignKey = $instance->foreignKey ?? mid($propertyTypeName);

                    $this->dynamicRelations[$propertyName] = fn () => $this->belongsTo($propertyTypeName, $foreignKey, $instance->ownerKey, $instance->relation)
                        ->with($instance->load);
                } elseif ($instance instanceof HasMany) {
                    $this->dynamicRelations[$propertyName] = fn () => $this->hasMany($instance->related, $instance->foreignKey, $instance->localKey)
                        ->with($instance->load);
                } elseif ($instance instanceof HasManyThrough) {
                    $this->dynamicRelations[$propertyName] = fn () => $this->hasManyThrough($instance->related, $instance->through, $instance->firstKey, $instance->secondKey, $instance->localKey, $instance->secondLocalKey)
                        ->with($instance->load);
                } elseif ($instance instanceof HasOne) {
                    $this->dynamicRelations[$propertyName] = fn () => $this->hasOne($propertyTypeName, $instance->foreignKey, $instance->localKey)
                        ->with($instance->load);
                } elseif ($instance instanceof HasOneThrough) {
                    $this->dynamicRelations[$propertyName] = fn () => $this->hasOneThrough($propertyTypeName, $instance->through, $instance->firstKey, $instance->secondKey, $instance->localKey, $instance->secondLocalKey)
                        ->with($instance->load);
                }

                // @phpstan-ignore property.notFound
                if ($instance instanceof Relationship && $instance->with) {
                    $this->with[] = $propertyName;
                }
            }
        }

        $this->fillable = array_unique($this->fillable);
        $this->hidden = array_unique($this->hidden);
        $this->appends = array_unique($this->appends);
        $this->with = array_unique($this->with);
        $this->casts = array_unique($this->casts);
    }

    // /////////////////////////////////

    protected static function bootHasColumnAttributes(): void
    {
        static::retrieved(fn ($model) => $model->initializeHasColumnAttributes());
        static::creating(fn ($model) => $model->initializeHasColumnAttributes());
    }
}
