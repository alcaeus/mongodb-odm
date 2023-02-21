<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Types;

use Doctrine\ODM\MongoDB\MongoDBException;
use MongoDB\BSON\Document;

use function is_array;

/**
 * The Hash type.
 */
class HashType extends Type
{
    public function convertToDatabaseValue($value)
    {
        if ($value instanceof Document) {
            return $value;
        }

        if ($value !== null && ! is_array($value)) {
            throw MongoDBException::invalidValueForType('Hash', ['array', 'object', 'null'], $value);
        }

        return $value !== null ? (object) $value : null;
    }

    public function convertToPHPValue($value)
    {
        if ($value instanceof Document) {
            return $value->toPHP(['root' => 'array']);
        }

        return $value !== null ? (array) $value : null;
    }
}
