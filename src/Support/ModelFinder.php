<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use ReflectionClass;

/** Finds concrete Eloquent models declared in PHP files under some paths. */
final class ModelFinder
{
    /**
     * @param  list<string>  $paths
     * @return list<class-string<Model>>
     */
    public static function in(array $paths): array
    {
        $models = [];

        foreach ($paths as $path) {
            if (! File::isDirectory($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $class = self::classIn($file->getContents());

                if ($class !== null && class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                    $models[] = $class;
                }
            }
        }

        sort($models);

        return array_values(array_unique($models));
    }

    private static function classIn(string $code): ?string
    {
        if (! preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $code, $class)) {
            return null;
        }

        $namespace = preg_match('/^namespace\s+([^;\s]+)\s*;/m', $code, $ns) ? $ns[1].'\\' : '';

        return $namespace.$class[1];
    }
}
