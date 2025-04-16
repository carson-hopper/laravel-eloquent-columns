<?php

declare(strict_types=1);

namespace EloquentColumn\Console\Commands;

use EloquentColumn\Attributes\Models\Column;
use EloquentColumn\Attributes\Models\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use ReflectionClass;

final class MigrationAutoCreateCommand extends Command
{
    protected $signature = 'migrate:auto-create';

    protected $description = 'Auto create migrations based on models';

    // /////////////////////////////////

    /**
     * @throws \ReflectionException
     */
    public function handle(): void
    {
        $models = [];
        foreach (File::allFiles(app()->basePath('app/Models')) as $file) {
            $relativePath = $file->getRelativePathname();
            $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $relativePath);

            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                $models[] = $class;
            }
        }

        foreach ($models as $_model) {
            if (! class_exists($_model)) {
                $this->error("Model class $_model does not exist.");
                return;
            }

            // @phpstan-ignore varTag.differentVariable
            $model = new $_model;

            $reflection = new ReflectionClass($model);
            if (!$reflection->hasMethod('getColumnDefinitions')) {
                $this->error("Model doesn't have HasColumnAttributes trait.");
                return;
            }

            foreach ($reflection->getAttributes(Table::class) as $attribute) {
                $columns = collect($model->getColumnDefinitions())->map(fn (array $column) => $column['column']);
                if (! Schema::hasTable($model->getTableName())) {
                    $this->generateInitialMigration($model, $columns);
                } else {
                    $this->generateUpdateMigration($model, $columns);
                }
            }


        }
    }

    // /////////////////////////////////

    private function generateInitialMigration(Model $model, Collection $columns): void
    {
        $fields = $this->generateFields($columns);

        $stub = <<<MIGRATION
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("{$model->getTableName()}", function (Blueprint \$table) {
            $fields
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("{$model->getTableName()}");
    }
};
MIGRATION;

        $filename = date('Y_m_d_His')."_create_{$model->getTableName()}_table.php";
        File::put(database_path("migrations/{$filename}"), $stub);

        $this->info("Created migration: {$filename}");
    }

    private function generateUpdateMigration(Model $model, Collection $columns): void
    {
        $existingColumns = collect(Schema::getColumnListing($model->getTableName()));

        $newColumns = $columns->except($existingColumns);
        $removedColumns = $existingColumns->diff($columns->keys());

        if ($newColumns->isEmpty() && $removedColumns->isEmpty()) {
            $this->info("No changes detected for table [{$model->getTableName()}].");

            return;
        }

        $filename = date('Y_m_d_His')."_update_{$model->getTableName()}_table.php";
        $migrationPath = database_path("migrations/{$filename}");

        $additions = $this->generateOrderedFields($model->getTableName(), $newColumns, $columns);
        $drops = collect($removedColumns)->map(fn ($col): string => "\$table->dropColumn('$col');")->implode("\n            ");

        $stub = <<<MIGRATION
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table("{$model->getTableName()}", function (Blueprint \$table) {
            {$additions}
            {$drops}
        });
    }

    public function down()
    {
        Schema::table("{$model->getTableName()}", function (Blueprint \$table) {
            // Manually revert changes here if necessary.
        });
    }
};
MIGRATION;

        File::put($migrationPath, $stub);
        $this->info("Created migration: {$filename}");
    }

    // /////////////////////////////////

    private function generateFields(Collection $columns): string
    {
        return $columns->map(function (Column $column, string $name) {
            $definition = Str::of($this->getDefinitions($column, $name));
            if ($definition->length() >= 1) {
                return $definition->append(';');
            }

            return $definition;
        })->filter(fn (Stringable $definition): bool => $definition->length() >= 1)->implode("\n            ");
    }

    private function generateOrderedFields(string $table, Collection $newColumns, Collection $columns): string
    {
        return $newColumns->map(function (Column $column, string $name) use ($columns, $table): string {
            $definition = $this->getDefinitions($column, $name);

            $currentIndex = array_search($name, $columns->keys()->toArray());
            $afterColumn = null;

            // Look backwards in the ordered column array to find an existing column
            for ($i = $currentIndex - 1; $i >= 0; $i--) {
                if (in_array($columns->keys()->toArray()[$i], Schema::getColumnListing($table))) {
                    $afterColumn = $columns->keys()->toArray()[$i];
                    break;
                }
            }

            if ($afterColumn) {
                $definition .= "->after('{$afterColumn}')";
            } else {
                $definition .= '->first()';
            }

            return "{$definition};";
        })->implode("\n            ");
    }

    private function getDefinitions(Column $column, string $name): string
    {
        $type = $column->type;
        $nullable = $column->nullable ? '->nullable()' : '';
        $default = $column->default !== null ? "->default('{$column->default}')" : '';
        $length = $column->length !== null && $column->length !== 0 ? ", {$column->length}" : '';
        $index = $column->index ? '->index()' : '';

        $definition = match ($type) {
            'id' => '$table->id()',
            'timestamps' => '$table->timestamps()',
            'rememberToken' => '$table->rememberToken()',
            default => "\$table->{$type}('{$name}'{$length}){$nullable}{$default}{$index}",
        };

        if (Str::of($name)->is('deleted_at')) {
            $definition = '$table->softDeletes()';
        } elseif (Str::of($name)->is('updated_at')) {
            $definition = '';
        } elseif (Str::of($name)->is('created_at')) {
            $definition = '$table->timestamps()';
        }

        return $definition;
    }
}
