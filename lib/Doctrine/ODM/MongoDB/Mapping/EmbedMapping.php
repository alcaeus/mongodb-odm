<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Mapping;

/** @internal */
class EmbedMapping extends AssociationMapping
{
    public readonly bool $embedded;
    public readonly bool $isOwningSide;

    /**
     * @param class-string|null           $targetDocument
     * @param array<string, class-string> $discriminatorMap
     */
    public function __construct(
        int $association,
        string $fieldName,
        ?string $name = null,
        bool $nullable = false,
        bool $notSaved = false,
        string $strategy = ClassMetadata::STORAGE_STRATEGY_SET,
        array $alsoLoadFields = [],
        string $type = ClassMetadata::ONE,
        ?string $targetDocument = null,
        ?string $discriminatorField = null,
        array $discriminatorMap = [],
        ?string $defaultDiscriminatorValue = null,
    ) {
        $this->embedded     = true;
        $this->isOwningSide = true;

        parent::__construct(
            association: $association,
            fieldName: $fieldName,
            name: $name,
            nullable: $nullable,
            notSaved: $notSaved,
            strategy: $strategy,
            alsoLoadFields: $alsoLoadFields,
            type: $type,
            targetDocument: $targetDocument,
            discriminatorField: $discriminatorField,
            discriminatorMap: $discriminatorMap,
            defaultDiscriminatorValue: $defaultDiscriminatorValue,
            isCascadePersist: true,
            isCascadeRemove: true,
            isCascadeRefresh: true,
            isCascadeDetach: true,
            isCascadeMerge: true,
            orphanRemoval: true,
        );
    }

    public static function fromMappingArray(array $mapping): EmbedOneMapping|EmbedManyMapping
    {
        return $mapping['type'] === ClassMetadata::ONE
            ? EmbedOneMapping::fromMappingArray($mapping)
            : EmbedManyMapping::fromMappingArray($mapping);
    }
}
