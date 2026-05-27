<?php

namespace AzYouness\RequestToFormBundle;

final readonly class ArgumentTypeMatcher
{
    private const BUILTIN_TYPES = [
        'array',
        'bool',
        'callable',
        'false',
        'float',
        'int',
        'iterable',
        'mixed',
        'null',
        'object',
        'resource',
        'string',
        'true',
        'never',
        'void',
    ];

    public function isBuiltInType(string $type): bool
    {
        return in_array($type, self::BUILTIN_TYPES, true);
    }

    public function matches(mixed $value, \ReflectionNamedType $type): bool
    {
        if (null === $value) {
            return $type->allowsNull();
        }

        $typeName = $type->getName();

        if (!$type->isBuiltin()) {
            return $value instanceof $typeName;
        }

        return match ($typeName) {
            'mixed' => true,
            'null' => null === $value,
            'bool' => is_bool($value),
            'int' => is_int($value),
            'float' => is_float($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'object' => is_object($value),
            'resource' => is_resource($value),
            // Syntax-only check avoids autoload/runtime callability checks.
            'callable' => is_callable($value, syntax_only: true),
            'iterable' => is_iterable($value),
            'true' => true === $value,
            'false' => false === $value,
            'never', 'void' => false,
            default => false,
        };
    }
}
