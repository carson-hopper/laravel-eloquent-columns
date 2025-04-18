<?php

declare(strict_types=1);

namespace EloquentColumn\Traits;

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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
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
        if ($method === 'create' && count($parameters) >= 1 && !($parameters[0]['__internal__'] ?? false)) {
            $columns = static::getColumnDefinitions();

            foreach ($columns->diffKeys(collect($parameters[0])->keys()) as $key => $row) {
                if ($row['column']->default) {
                    $parameters[0][$key] = $row['column']->default;
                }
            }

            foreach ($parameters[0] as $key => $parameter) {
                if (class_exists($key)) {
                    $instance = new $key;
                    if ($instance instanceof Model) {
                        unset($parameters[0][$key]);
                        $parameters[0][mid($instance)] = $parameter;
                    }
                }
            }

            if ($parentClass = static::getParent()) {
                $parameters[0]['type'] = static::class;

                $parameters[0]['__internal__'] = true;
                $parent = $parentClass::create($parameters[0]);
                unset($parameters[0]['__internal__']);

                $parameters[0][mid(new $parentClass)] = $parent->id;
            }
        }

        return parent::__callStatic($method, $parameters);
    }

    // /////////////////////////////////

    /**
     * Get the class name for polymorphic relations.
     */
    public function getMorphClass(): string
    {
        if (static::getChildren()->isEmpty()) {
            return parent::getMorphClass();
        }

        $parentClass = static::getParent();
        return (new $parentClass)->getMorphClass();
    }

    // /////////////////////////////////

    public static function getTableName(): string
    {
        $reflection = new ReflectionClass(static::class);
        foreach ($reflection->getAttributes(Table::class) as $attribute) {
            return $attribute->newInstance()->table;
        }

        return Str::snake(Str::pluralStudly($reflection->getShortName()));
    }

    public static function getColumnDefinitions(): Collection
    {
        $definitions = [];
        $reflection = new ReflectionClass(static::class);
        $parentClass = static::getParent();

        foreach ($reflection->getProperties() as $property) {
            foreach ($property->getAttributes(Column::class) as $attribute) {
                /** @var Column $column */
                $column = $attribute->newInstance();

                $name = $column->name ?? Str::snake($property->getName());
                // @phpstan-ignore method.notFound
                $propertyType = $property->getType()->getName();
                if (class_exists($propertyType)) {
                    $instance = new $propertyType();

                    if (is_subclass_of($instance, Model::class)) {
                        $name = $column->name ?? mid($instance);
                        $column->type = 'integer';
                    }
                }

                if (($baseClass = static::getBaseClass(static::class)) !== null) {
                    $baseClass = resolve($baseClass);

                    $baseClassColumns = $baseClass::getColumnDefinitions();
                    $parentColumns = $parentClass ? $parentClass::getColumnDefinitions() : collect([]);

                    if (!$baseClassColumns->keys()->contains($name) && $parentColumns->keys()->contains($name)) {
                        continue;
                    }
                }

                $definitions[$name] = [
                    'column' => $column,
                    'property' => $property,
                ];
            }
        }

        if ($parentClass = static::getParent()) {
            $parentClass = resolve($parentClass);

            $definitions[mid($parentClass)] = [
                'column' => new Column(type: 'integer', hidden: true, nullable: true),
                'property' => null,
            ];
        }

        if (static::getChildren()->count() >= 1 && !isset($definitions['type'])) {
            $definitions['type'] = [
                'column' => new Column(name: 'type', type: 'string', hidden: true, nullable: true),
                'property' => null,
            ];
        }

        return collect($definitions);
    }

    /**
     * @return array<int, sstring>
     */
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

    public static function getParent(): ?string
    {
        $reflector = new ReflectionClass(static::class);
        foreach ($reflector->getAttributes(Table::class) as $attribute) {
            return $attribute->newInstance()->parent;
        }
        return null;
    }

    public static function getChildren(): Collection
    {
        $reflector = new ReflectionClass(static::class);
        $basePath = dirname($reflector->getFileName());
        $baseNamespace = $reflector->getNamespaceName();

        $classes = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($basePath));
        foreach ($rii as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = Str::after($file->getPathname(), $basePath . DIRECTORY_SEPARATOR);
            $classPath = str_replace(['/', '\\'], '\\', Str::replaceLast('.php', '', $relativePath));
            $fullClass = $baseNamespace . '\\' . $classPath;

            if (!class_exists($fullClass) || !is_subclass_of($fullClass, static::class)) {
                continue;
            }

            $reflected = new ReflectionClass($fullClass);
            if (!$reflected->isInstantiable()) {
                continue;
            }

            $classes[] = $fullClass;
        }

        return collect($classes);
    }

    private static function getBaseClass(string $class): ?string
    {
        $parent = (new ReflectionClass($class))->getParentClass();
        if ($parent->getName() === Model::class) {
            return null;
        } else if ($parent->getName() !== \EloquentColumn\Models\Model::class) {
            return static::getBaseClass($parent->getName());
        }
        return $parent->getName();
    }

    public function initializeHasColumnAttributes(): void
    {
        $this->table = $this->getTableName();

        foreach ($this->getColumnDefinitions() as $name => $definition) {
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

    public function newFromBuilder($attributes = [], $connection = null)
    {
        if (isset($attributes?->type) && is_string($attributes->type) && class_exists($attributes->type)) {
            $class = $attributes->type;

            if (is_subclass_of($class, static::class)) {
                if ($attributes?->id == null) {
                    return parent::newFromBuilder($attributes, $connection);
                }

                $instance = (new $class)->newInstance([], true);
                $instance->setConnection($connection ?? $this->getConnectionName());

                $child = \DB::table($class::getTableName())->where(mid(static::class), $attributes->id)->first();
                if ($child) {
                    $instance->setRawAttributes([...(array)$attributes, ...(array)$child], true);
                    $instance->syncOriginal();
                }

                $instance->fillable = array_unique([...$instance->fillable ?? [], ...$this->fillable ?? []]);
                $instance->hidden = array_unique([...$instance->hidden ?? [], ...$this->hidden ?? []]);
                $instance->casts = array_unique([...$instance->casts ?? [], ...$this->casts ?? []]);
                return $instance;
            }
        }

        $model = parent::newFromBuilder($attributes, $connection);

        if ($parentClass = static::getParent()) {
            $foreignKey = mid(new $parentClass);

            // fetch parent using its primary key
            $parent = (new $parentClass)->newQueryWithoutScopes()->find($attributes->{$foreignKey});

            if ($parent) {
                unset($attributes->{$foreignKey});

                // Merge parent attributes into this child instance
                $model->setRawAttributes(array_merge(
                    $parent->getAttributes(),
                    (array)$attributes
                ), true);

                // Inherit relationships, casts, etc. if needed
                $model->syncOriginal();

                $model->fillable = array_unique([...$model->fillable ?? [], ...$this->fillable ?? []]);
                $model->hidden = array_unique([...$model->hidden ?? [], ...$this->hidden ?? []]);
                $model->casts = array_unique([...$model->casts ?? [], ...$this->casts ?? []]);
            }
        }

        return $model;
    }

    public function delete(): bool|null
    {
        if (static::getChildren()->isNotEmpty() && $this->type && is_string($this->type) && class_exists($this->type)) {
            $childClass = $this->type;

            if (is_subclass_of($childClass, static::class) && !($this instanceof $childClass) && $child = $childClass::find($this->getKey())) {
                return $child->delete();
            }
        } else if ($parentClass = static::getParent()) {
            $parent = resolve($parentClass);

            \DB::transaction(function () use ($parentClass, $childAttributes, $parentAttributes) {
                \DB::table($this->getTable())
                    ->where(mid($parentClass), $this->{mid($parentClass)})
                    ->delete();

                \DB::table($parentClass::getTableName())
                    ->where($parent->getKeyName(), $this->getKey())
                    ->delete();
            });

            return true;
        }

        return parent::delete();
    }

    public function save(array $options = [])
    {
        if ($parentClass = static::getParent()) {
            $parentColumns = $parentClass::getColumnDefinitions()->keys();
            $childColumns = static::getColumnDefinitions()->keys()->diff($parentColumns);

            $parentAttributes = collect($this->getDirty())->only($parentColumns);
            $childAttributes = collect($this->getDirty())->only($childColumns);

            \DB::transaction(function () use ($parentClass, $childAttributes, $parentAttributes) {
                if ($childAttributes->isNotEmpty()) {
                    $existing = \DB::table($this->getTable())
                        ->where(mid($parentClass), $this->{mid($parentClass)})
                        ->exists();

                    if ($existing) {
                        \DB::table($this->getTable())
                            ->where(mid($parentClass), $this->{mid($parentClass)})
                            ->update($childAttributes->all());
                    } else {

                        \DB::table($this->getTable())
                            ->insert([
                                ...$childAttributes->all(),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                    }
                }

                if ($parentAttributes->isNotEmpty()) {
                    \DB::table($parentClass::getTableName())
                        ->where(resolve($parentClass)->getKeyName(), $this->getKey())
                        ->update($parentAttributes->all());
                }
            });

            return true;
        }

        return parent::save($options);
    }


    // /////////////////////////////////

    protected static function bootHasColumnAttributes(): void
    {
        static::retrieved(fn ($model) => $model->initializeHasColumnAttributes());
        static::creating(fn ($model) => $model->initializeHasColumnAttributes());
    }
}
