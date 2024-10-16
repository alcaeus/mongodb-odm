<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Mapping;

use InvalidArgumentException;

use function array_search;
use function sprintf;

/**
 * @internal
 * @phpstan-import-type FieldMappingConfig from ClassMetadata
 */
abstract class AssociationMapping extends FieldMapping
{
    /**
     * @param class-string|null           $targetDocument
     * @param array<string, class-string> $discriminatorMap
     * @param list<string>                $alsoLoadFields
     */
    public function __construct(
        ClassMetadata $owningDocument,
        public readonly int $association,
        string $fieldName,
        ?string $name = null,
        bool $nullable = false,
        bool $notSaved = false,
        string $strategy = ClassMetadata::STORAGE_STRATEGY_SET,
        array $alsoLoadFields = [],
        public readonly string $type = ClassMetadata::ONE,
        public readonly ?string $targetDocument = null,
        public readonly ?string $discriminatorField = null,
        public readonly array $discriminatorMap = [],
        public readonly ?string $defaultDiscriminatorValue = null,
        public readonly bool $isCascadePersist = false,
        public readonly bool $isCascadeRemove = false,
        public readonly bool $isCascadeRefresh = false,
        public readonly bool $isCascadeDetach = false,
        public readonly bool $isCascadeMerge = false,
        public readonly bool $orphanRemoval = false,
    ) {
        parent::__construct(
            owningDocument: $owningDocument,
            fieldName: $fieldName,
            name: $name,
            nullable: $nullable,
            notSaved: $notSaved,
            strategy: $strategy,
            alsoLoadFields: $alsoLoadFields,
        );
    }

    /** @phpstan-param FieldMappingConfig $mapping */
    public static function fromMappingArray(ClassMetadata $owningDocument, array $mapping): EmbedOneMapping|EmbedManyMapping|ReferenceOneMapping|ReferenceManyMapping
    {
        if (isset($mapping['embedded'])) {
            return EmbedMapping::fromMappingArray($owningDocument, $mapping);
        }

        if (isset($mapping['reference'])) {
            return ReferenceMapping::fromMappingArray($owningDocument, $mapping);
        }

        throw new InvalidArgumentException(sprintf('Invalid mapping detected for field %s', $mapping['fieldName']));
    }

    /** @return array{string, mixed} */
    public function getDiscriminatorData(ClassMetadata $class): array
    {
        $discriminatorValue = null;

        if (isset($this->discriminatorField)) {
            $discriminatorField = $this->discriminatorField;
            $discriminatorMap   = $this->discriminatorMap;
        } else {
            $discriminatorField = $class->discriminatorField;
            $discriminatorValue = $class->discriminatorValue;
            $discriminatorMap   = $class->discriminatorMap;
        }

        if ($discriminatorField === null) {
            return [];
        }

        if ($discriminatorValue === null) {
            if (! empty($discriminatorMap)) {
                $pos = array_search($class->name, $discriminatorMap);

                if ($pos !== false) {
                    $discriminatorValue = $pos;
                }
            } else {
                $discriminatorValue = $class->name;
            }
        }

        if ($discriminatorValue === null) {
            throw MappingException::unlistedClassInDiscriminatorMap($class->name);
        }

        return [$discriminatorField => $discriminatorValue];
    }
}
