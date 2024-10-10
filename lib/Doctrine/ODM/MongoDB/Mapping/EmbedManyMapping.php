<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Mapping;

use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Utility\CollectionHelper;

/** @internal */
class EmbedManyMapping extends EmbedMapping implements AssociationCollectionMapping
{
    /**
     * @param class-string|null             $targetDocument
     * @param array<string, class-string>   $discriminatorMap
     * @param class-string<Collection>|null $collectionClass
     */
    public function __construct(
        string $fieldName,
        ?string $name = null,
        bool $nullable = false,
        bool $notSaved = false,
        string $strategy = CollectionHelper::DEFAULT_STRATEGY,
        array $alsoLoadFields = [],
        ?string $targetDocument = null,
        ?string $discriminatorField = null,
        array $discriminatorMap = [],
        ?string $defaultDiscriminatorValue = null,
        public readonly ?string $collectionClass = null,
        public readonly bool $storeEmptyArray = false,
    ) {
        parent::__construct(
            association: ClassMetadata::EMBED_MANY,
            fieldName: $fieldName,
            name: $name,
            nullable: $nullable,
            notSaved: $notSaved,
            strategy: $strategy,
            alsoLoadFields: $alsoLoadFields,
            type: ClassMetadata::MANY,
            targetDocument: $targetDocument,
            discriminatorField: $discriminatorField,
            discriminatorMap: $discriminatorMap,
            defaultDiscriminatorValue: $defaultDiscriminatorValue,
        );
    }

    public static function fromMappingArray(array $mapping): self
    {
        return new self(
            fieldName: $mapping['fieldName'],
            name: $mapping['name'],
            nullable: $mapping['nullable'] ?? false,
            notSaved: $mapping['notSaved'] ?? false,
            strategy: $mapping['strategy'] ?? CollectionHelper::DEFAULT_STRATEGY,
            alsoLoadFields: $mapping['alsoLoadFields'] ?? [],
            targetDocument: $mapping['targetDocument'],
            discriminatorField: $mapping['discriminatorField'],
            discriminatorMap: $mapping['discriminatorMap'] ?? [],
            defaultDiscriminatorValue: $mapping['defaultDiscriminatorValue'],
            collectionClass: $mapping['collectionClass'],
            storeEmptyArray: $mapping['storeEmptyArray'] ?? false,
        );
    }
}
