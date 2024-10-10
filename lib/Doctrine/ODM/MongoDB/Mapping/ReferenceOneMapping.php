<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Mapping;

use function in_array;

class ReferenceOneMapping extends ReferenceMapping
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
        ?string $inversedBy = null,
        ?string $mappedBy = null,
        ?string $repositoryMethod = null,
        ?array $criteria = null,
        ?array $sort = null,
        bool $isCascadePersist = false,
        bool $isCascadeRemove = false,
        bool $isCascadeRefresh = false,
        bool $isCascadeDetach = false,
        bool $isCascadeMerge = false,
        bool $orphanRemoval = false,
        string $storeAs = ClassMetadata::REFERENCE_STORE_AS_ID,
        array $prime = [],
    ) {
        parent::__construct(
            owningDocument: $owningDocument,
            association: ClassMetadata::REFERENCE_ONE,
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
            inversedBy: $mapping['inversedBy'] ?? null,
            mappedBy: $mapping['mappedBy'] ?? null,
            repositoryMethod: $mapping['repositoryMethod'] ?? null,
            criteria: $mapping['criteria'] ?? null,
            sort: $mapping['sort'] ?? null,
            isCascadePersist: in_array('persist', $mapping['cascade']),
            isCascadeRemove: in_array('remove', $mapping['cascade']),
            isCascadeRefresh: in_array('refresh', $mapping['cascade']),
            isCascadeDetach: in_array('detach', $mapping['cascade']),
            isCascadeMerge: in_array('merge', $mapping['cascade']),
            orphanRemoval: $mapping['orphanRemoval'] ?? false,
            storeAs: $mapping['storeAs'] ?? ClassMetadata::REFERENCE_STORE_AS_DB_REF,
            prime: $mapping['prime'] ?? [],
        );
    }
}
