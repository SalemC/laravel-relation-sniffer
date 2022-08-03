<?php

namespace LaravelRelationSniffer;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use ReflectionClass;
use Throwable;
use Closure;

class LaravelRelationSniffer {
    /**
     * The map of relations.
     *
     * @var \Illuminate\Support\Collection
     */
    private $map;

    /**
     * The methods to ignore for their models.
     *
     * @var array
     */
    private $nonRelationalMethods = [
        '*' => [
            'save',
            'update',
            'delete',
            'forceDelete',
        ],

        // 'App\\Models\\User' => [
        //     'exampleMethod',
        // ],
    ];

    /**
     * Construct this class.
     *
     * @param ?array $nonRelationalMethods
     */
    public function __construct(?array $nonRelationalMethods = []) {
        $this->map = collect();

        $this->setNonRelationalMethods($nonRelationalMethods);
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

    /**
     * Validate a potential relation method based on its return type.
     *
     * @param mixed $returnType The return type of the method.
     *
     * @return bool
     */
    private function validateRelation(mixed $returnType): bool {
        return $returnType !== null && (is_a($returnType, Relation::class) || is_subclass_of($returnType, Relation::class));
    }

    /**
     * Set the non relational methods.
     *
     * @param array $nonRelationalMethods
     *
     * @return void
     */
    public function setNonRelationalMethods(array $nonRelationalMethods): void {
        $this->nonRelationalMethods = $nonRelationalMethods;
    }

    /**
     * Sniff for relations.
     *
     * @return void
     */
    public function sniff(): array {
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
                    if (Str::startsWith($methodName, '__')) return $acc;
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
                        Log::error($methodName . ' - failed');

                        $errorMessage = $e->getMessage();

                        Log::error($errorMessage);

                        if (Str::startsWith($errorMessage, 'Class "') && Str::endsWith($errorMessage, ' not found')) {
                            Log::info('- This could be due to an incorrect relation setup');
                        }
                    }

                    if (!$this->validateRelation($returnType)) return $acc;

                    return $acc->put($methodName, $returnType);
                }, collect());

            // Restore the original saving method.
            $modelClass::saving($savingMethod);

            if (!$this->map->has($modelClass)) {
                $this->map->put($modelClass, collect([
                    'metadata' => collect([
                        'table' => $modelInstance->getTable(),
                        'class' => $modelClass,
                    ]),
                    'relations' => collect(),
                ]));
            }

            $relationMethods
                ->keys()
                ->each(function ($relation) use ($modelClass, $modelInstance) {
                    $data = collect();

                    $relationBuilder = $modelInstance->$relation();

                    $isPivot = is_a($relationBuilder, BelongsToMany::class) || is_subclass_of($relationBuilder, BelongsToMany::class);

                    $data->put('isPivot', $isPivot);
                    $data->put('relatedModel', get_class($relationBuilder->getRelated()));

                    if ($isPivot) {
                        $data->put('foreignKey', $relationBuilder->getForeignPivotKeyName());
                        $data->put('parentKey', $relationBuilder->getParentKeyName());
                        $data->put('relatedPivotKey', $relationBuilder->getRelatedPivotKeyName());
                        $data->put('relatedKey', $relationBuilder->getRelatedKeyName());
                        $data->put('table', $relationBuilder->getTable());
                    } else {
                        $localKey = is_a($relationBuilder, BelongsTo::class)
                            ? $relationBuilder->getOwnerKeyName()
                            : $relationBuilder->getLocalKeyName();

                        $data->put('foreignKey', $relationBuilder->getForeignKeyName());
                        $data->put('localKey', $localKey);
                    }

                    $this
                        ->map
                        ->get($modelClass)
                        ->get('relations')
                        ->put($relation, $data);
                });
        });

        DB::rollBack();

        return $this->map->values()->toArray();
    }
}
