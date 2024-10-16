<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Mapping;

use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Utility\CollectionHelper;

use function in_array;

/**
 * @internal
 * @phpstan-import-type FieldMappingConfig from ClassMetadata
 */
class ReferenceManyMapping extends ReferenceMapping implements AssociationCollectionMapping
{
    /**
     * @param class-string|null             $targetDocument
     * @param array<string, class-string>   $discriminatorMap
     * @param class-string<Collection>|null $collectionClass
     */
    public function __construct(
        ClassMetadata $owningDocument,
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
        ?string $inversedBy = null,
        ?string $mappedBy = null,
        ?string $repositoryMethod = null,
        ?array $criteria = null,
        ?array $sort = null,
        public readonly ?int $limit = null,
        public readonly ?int $skip = null,
        bool $isCascadePersist = false,
        bool $isCascadeRemove = false,
        bool $isCascadeRefresh = false,
        bool $isCascadeDetach = false,
        bool $isCascadeMerge = false,
        bool $orphanRemoval = false,
        string $storeAs = ClassMetadata::REFERENCE_STORE_AS_ID,
        array $prime = [],
        public readonly ?string $collectionClass = null,
        public readonly bool $storeEmptyArray = false,
    ) {
        parent::__construct(
            owningDocument: $owningDocument,
            association: ClassMetadata::REFERENCE_MANY,
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
            inversedBy: $inversedBy,
            mappedBy: $mappedBy,
            repositoryMethod: $repositoryMethod,
            criteria: $criteria,
            sort: $sort,
            isCascadePersist: $isCascadePersist,
            isCascadeRemove: $isCascadeRemove,
            isCascadeRefresh: $isCascadeRefresh,
            isCascadeDetach: $isCascadeDetach,
            isCascadeMerge: $isCascadeMerge,
            orphanRemoval: $orphanRemoval,
            storeAs: $storeAs,
            prime: $prime,
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
            strategy: $mapping['strategy'] ?? CollectionHelper::DEFAULT_STRATEGY,
            alsoLoadFields: $mapping['alsoLoadFields'] ?? [],
            targetDocument: $mapping['targetDocument'],
            discriminatorField: $mapping['discriminatorField'],
            discriminatorMap: $mapping['discriminatorMap'] ?? [],
            defaultDiscriminatorValue: $mapping['defaultDiscriminatorValue'],
            inversedBy: $mapping['inversedBy'] ?? null,
            mappedBy: $mapping['mappedBy'] ?? null,
            repositoryMethod: $mapping['repositoryMethod'] ?? null,
            criteria: $mapping['criteria'] ?? null,
            sort: $mapping['sort'] ?? null,
            limit: $mapping['limit'] ?? null,
            skip: $mapping['skip'] ?? null,
            isCascadePersist: in_array('persist', $mapping['cascade']),
            isCascadeRemove: in_array('remove', $mapping['cascade']),
            isCascadeRefresh: in_array('refresh', $mapping['cascade']),
            isCascadeDetach: in_array('detach', $mapping['cascade']),
            isCascadeMerge: in_array('merge', $mapping['cascade']),
            orphanRemoval: $mapping['orphanRemoval'] ?? false,
            storeAs: $mapping['storeAs'] ?? ClassMetadata::REFERENCE_STORE_AS_DB_REF,
            prime: $mapping['prime'] ?? [],
            collectionClass: $mapping['collectionClass'],
            storeEmptyArray: $mapping['storeEmptyArray'] ?? false,
        );
    }
}
