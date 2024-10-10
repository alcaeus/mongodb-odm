<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Mapping;

use ArrayAccess;

/** @internal */
abstract class FieldMapping implements ArrayAccess
{
    use ArrayAccessImplementation;

    /** Stores the name of the database field */
    public readonly string $name;

    /** @var class-string|null */
    public ?string $declared;

    /** @var class-string|null */
    public ?string $inherited;

    public function __construct(
        /** Stores the property name in the mapped class */
        public readonly string $fieldName,
        ?string $name = null,
        public readonly bool $nullable = false,
        public readonly bool $notSaved = false,
        public readonly string $strategy = ClassMetadata::STORAGE_STRATEGY_SET,
        public readonly array $alsoLoadFields = [],
    ) {
        $this->name = $name ?? $fieldName;
    }

    public static function fromMappingArray(array $mapping): TypedFieldMapping|EmbedOneMapping|EmbedManyMapping|ReferenceOneMapping|ReferenceManyMapping
    {
        if (($mapping['association'] ?? false) || ($mapping['reference'] ?? false) || ($mapping['embedded'] ?? false)) {
            return AssociationMapping::fromMappingArray($mapping);
        }

        return TypedFieldMapping::fromMappingArray($mapping);
    }

    /**
     * @param class-string      $declaringClass
     * @param class-string|null $inheritedFrom
     */
    public function inherit(string $declaringClass, ?string $inheritedFrom): static
    {
        $clone            = clone $this;
        $clone->declared  = $declaringClass;
        $clone->inherited = $inheritedFrom;

        return $clone;
    }
}
