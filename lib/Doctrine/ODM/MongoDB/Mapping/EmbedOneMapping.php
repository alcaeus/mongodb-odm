<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Mapping;

/**
 * @internal
 * @phpstan-import-type FieldMappingConfig from ClassMetadata
 */
class EmbedOneMapping extends EmbedMapping
{
    /**
     * @param class-string|null           $targetDocument
     * @param array<string, class-string> $discriminatorMap
     */
    public function __construct(
        ClassMetadata $owningDocument,
        string $fieldName,
        ?string $name = null,
        bool $nullable = false,
        bool $notSaved = false,
        string $strategy = ClassMetadata::STORAGE_STRATEGY_SET,
        array $alsoLoadFields = [],
        ?string $targetDocument = null,
        ?string $discriminatorField = null,
        array $discriminatorMap = [],
        ?string $defaultDiscriminatorValue = null,
    ) {
        parent::__construct(
            owningDocument: $owningDocument,
            association: ClassMetadata::EMBED_ONE,
            fieldName: $fieldName,
            name: $name,
            nullable: $nullable,
            notSaved: $notSaved,
            strategy: $strategy,
            alsoLoadFields: $alsoLoadFields,
            type: ClassMetadata::ONE,
            targetDocument: $targetDocument,
            discriminatorField: $discriminatorField,
            discriminatorMap: $discriminatorMap,
            defaultDiscriminatorValue: $defaultDiscriminatorValue,
        );
    }

    /** @phpstan-param FieldMappingConfig $mapping */
    public static function fromMappingArray(ClassMetadata $owningDocument, array $mapping): self
    {
        return new self(
            owningDocument: $owningDocument,
            fieldName: $mapping['fieldName'],
            name: $mapping['name'],
            nullable: $mapping['nullable'] ?? false,
            notSaved: $mapping['notSaved'] ?? false,
            strategy: $mapping['strategy'] ?? ClassMetadata::STORAGE_STRATEGY_SET,
            alsoLoadFields: $mapping['alsoLoadFields'] ?? [],
            targetDocument: $mapping['targetDocument'],
            discriminatorField: $mapping['discriminatorField'],
            discriminatorMap: $mapping['discriminatorMap'] ?? [],
            defaultDiscriminatorValue: $mapping['defaultDiscriminatorValue'],
        );
    }
}
