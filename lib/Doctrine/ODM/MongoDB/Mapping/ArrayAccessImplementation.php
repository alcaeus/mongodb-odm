<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Mapping;

use InvalidArgumentException;

use function property_exists;
use function trigger_deprecation;

/** @internal */
trait ArrayAccessImplementation
{
    /** @param string $offset */
    public function offsetExists(mixed $offset): bool
    {
        $this->triggerDeprecation();

        return isset($this->$offset);
    }

    /** @param string $offset */
    public function offsetGet(mixed $offset): mixed
    {
        $this->triggerDeprecation();

        if (! property_exists($this, $offset)) {
            throw new InvalidArgumentException('Undefined property: ' . $offset);
        }

        return $this->$offset;
    }

    /** @param string $offset */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->triggerDeprecation();

        $this->$offset = $value;
    }

    /** @param string $offset */
    public function offsetUnset(mixed $offset): void
    {
        $this->triggerDeprecation();

        $this->$offset = null;
    }

    private function triggerDeprecation(): void
    {
        trigger_deprecation(
            'doctrine/mongodb-odm',
            '2.10',
            'Using ArrayAccess on %s is deprecated and will not be possible in Doctrine ODM 3.0. Use the corresponding property instead.',
            static::class,
        );
    }
}
