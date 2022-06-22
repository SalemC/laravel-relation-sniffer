<?php

namespace App\Console\Commands;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Collection;
use Illuminate\Console\Command;

use ReflectionClass;
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
     * Execute the console command.
     *
     * @return int
     */
    public function handle() {
        DB::beginTransaction();

        // @todo ignore pivot models?
        $this->getReflectedModels()->each(function ($model) {
            $modelInstance = app()->make($model->getName());

            $relationMethods = collect($model->getMethods())
                ->filter(function ($method) use ($modelInstance) {
                    $methodName = $method->getName();

                    if ($method->isStatic()) return false;
                    if (!$method->isPublic()) return false;
                    if (str_starts_with($methodName, '__')) return false;
                    if ($method->getNumberOfParameters() > 0) return false;

                    $returnType = $method->getReturnType();

                    if ($returnType !== null) {
                        if (is_a($returnType, Relation::class) || is_subclass_of($returnType, Relation::class)) return true;
                    }

                    try {
                        $returnType = $modelInstance->$methodName();
                    } catch (\Exception $e) {
                        echo "$methodName - failed\n";
                        echo $e->getMessage() . "\n";
                    }

                    return $returnType instanceof Relation;
                });

            dd($relationMethods);
        });

        DB::rollBack();

        return Command::SUCCESS;
    }
}
