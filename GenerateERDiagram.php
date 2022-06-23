<?php

namespace App\Console\Commands;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Collection;
use Illuminate\Console\Command;

use ReflectionClass;
use Throwable;
use Closure;
use Str;
use DB;

class GenerateERDiagram extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'erd:generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate an ERD diagram from all models';

    /**
     * The methods to ignore for their models.
     *
     * @var array
     */
    private $nonRelationalMethods = [
        '*' => [
            'save',
            'delete',
            'forceDelete',
        ],

        //
    ];

    /**
     * The map of relations.
     *
     * @var \Illuminate\Support\Collection
     */
    private $map;

    public function __construct() {
        parent::__construct();

        $this->map = collect();
    }

    /**
     * Get all the reflected models.
     *
     * @return \Illuminate\Support\Collection
     */
    private function getReflectedModels(): Collection {
        return collect(File::allFiles(app_path('Models')))
            ->reduce(function ($acc, $item) {
                $path = $item->getRelativePathName();

                $fqcn = 'App\Models\\' . strtr(substr($path, 0, strrpos($path, '.')), '/', '\\');

                $reflection = new ReflectionClass($fqcn);

                if (!class_exists($fqcn)) return $acc;

                if ($reflection->isAbstract()) return $acc;
                if (!$reflection->isSubclassOf(Model::class)) return $acc;

                return $acc->merge([$reflection]);
            }, collect());
    }

    private function validateRelation(mixed $returnType): bool {
        return $returnType !== null && (is_a($returnType, Relation::class) || is_subclass_of($returnType, Relation::class));
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle() {
        DB::beginTransaction();

        $nonRelationalMethods = collect($this->nonRelationalMethods);

        // @todo add methods to ignore for each model
        // @todo ignore pivot models?
        $this->getReflectedModels()->each(function ($model) use ($nonRelationalMethods) {
            $modelClass = $model->getName();

            // Create a copy of any existing 'saving' methods for every model.
            $savingMethod = Closure::fromCallable([$modelClass, 'saving']);

            $modelInstance = app()->make($modelClass);

            // Prevent database changes occuring while scanning for relations.
            $modelClass::saving(fn () => false);

            $relationMethods = collect($model->getMethods())
                ->filter(function ($method) use ($nonRelationalMethods, $modelClass) {
                    $methodName = $method->getName();

                    $nonRelationalModelMethods = $nonRelationalMethods->get($modelClass);
                    $nonRelationalWildcards = $nonRelationalMethods->get('*');

                    if (is_array($nonRelationalModelMethods) && in_array($methodName, $nonRelationalModelMethods)) return false;
                    if (is_array($nonRelationalWildcards) && in_array($methodName, $nonRelationalWildcards)) return false;

                    return true;
                })->reduce(function ($acc, $method) use ($modelInstance) {
                    $methodName = $method->getName();

                    if ($method->isStatic()) return $acc;
                    if (!$method->isPublic()) return $acc;
                    if (str_starts_with($methodName, '__')) return $acc;
                    if ($method->getNumberOfParameters() > 0) return $acc;

                    $returnType = $method->getReturnType();

                    if ($returnType !== null && $this->validateRelation($returnType)) {
                        return $acc->put($methodName, $returnType->getName());
                    }

                    try {
                        $returnValue = $modelInstance->$methodName();

                        if (!is_object($returnValue)) return $acc;

                        $returnType = get_class($returnValue);
                    } catch (Throwable $e) {
                        $this->error($methodName . ' - failed');

                        $errorMessage = $e->getMessage();

                        $this->error($errorMessage);

                        if (Str::startsWith($errorMessage, 'Class "') && Str::endsWith($errorMessage, ' not found')) {
                            $this->info('- This could be due to an incorrect relation setup');
                        }

                        $this->newLine();
                    }

                    if (!$this->validateRelation($returnType)) return $acc;

                    return $acc->put($methodName, $returnType);
                }, collect());

            // Restore the original saving method.
            $modelClass::saving($savingMethod);

            if (!$this->map->has($modelClass)) $this->map->put($modelClass, collect());

            $relationMethods->each(function ($type, $relation) use ($modelClass, $modelInstance) {
                $this->map->get($modelClass)->put($relation, [
                    'table' => $modelInstance->$relation()->getRelated()->getTable(),
                ]);
            });
        });

        DB::rollBack();

        dd($this->map);

        return Command::SUCCESS;
    }
}
