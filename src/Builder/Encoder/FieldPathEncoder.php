<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Builder\Encoder;

use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use MongoDB\Builder\Type\FieldPathInterface;
use MongoDB\Codec\EncodeIfSupported;
use MongoDB\Codec\Encoder;
use MongoDB\Exception\UnsupportedValueException;

/**
 * @template-implements Encoder<string, FieldPathInterface>
 *
 * @internal
 */
final class FieldPathEncoder implements Encoder
{
    /** @template-use EncodeIfSupported<string, FieldPathInterface> */
    use EncodeIfSupported;

    public function __construct(private ClassMetadata $class)
    {
    }

    public function canEncode(mixed $value): bool
    {
        return $value instanceof FieldPathInterface;
    }

    public function encode(mixed $value): string
    {
        if (! $this->canEncode($value)) {
            throw UnsupportedValueException::invalidEncodableValue($value);
        }

        return $this->class->hasField($value->name)
            ? '$' . $this->class->getFieldMapping($value->name)['name']
            : '$' . $value->name;
    }
}
