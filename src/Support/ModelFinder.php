<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use ReflectionClass;

/** Finds concrete Eloquent models declared in PHP files under some paths (parsed with the PHP tokenizer). */
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

    /** The first real class declared in $code (comments, strings, Foo::class and new class are skipped). */
    public static function classIn(string $code): ?string
    {
        $namespace = '';
        $previous = null;
        $tokens = array_values(array_filter(token_get_all($code), fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));

        foreach ($tokens as $i => $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $next = $tokens[$i + 1] ?? null;
                $namespace = is_array($next) && in_array($next[0], [T_NAME_QUALIFIED, T_STRING], true) ? $next[1].'\\' : '';
            }

            if (is_array($token) && $token[0] === T_CLASS && ! (is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true))) {
                $name = $tokens[$i + 1] ?? null;

                if (is_array($name) && $name[0] === T_STRING) {
                    return $namespace.$name[1];
                }
            }

            $previous = $token;
        }

        return null;
    }
}
