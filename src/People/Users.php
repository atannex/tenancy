<?php

declare(strict_types=1);

namespace Atannex\Tenancy\People;

use Illuminate\Database\Eloquent\Model;

/** @internal The app's user model. */
final class Users
{
    /** @return class-string<Model> */
    public static function model(): string
    {
        return config('tenancy.people.user_model') ?: config('auth.providers.users.model');
    }

    public static function find(string|int|null $key): ?Model
    {
        if ($key === null || $key === '') {
            return null;
        }

        $model = self::model();

        return $model::query()->find($key);
    }

    public static function key(Model $user): string
    {
        return (string) $user->getKey();
    }
}
