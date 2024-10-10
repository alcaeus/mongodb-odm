<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Mapping;

use LogicException;

use function array_map;
use function in_array;

class ReferenceMapping extends AssociationMapping
{
    public readonly bool $isOwningSide;
    public readonly bool $isInverseSide;
    public readonly bool $reference;

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
        public readonly ?string $inversedBy = null,
        public readonly ?string $mappedBy = null,
        public readonly ?string $repositoryMethod = null,
        public ?array $criteria = null,
        public ?array $sort = null,
        bool $isCascadePersist = false,
        bool $isCascadeRemove = false,
        bool $isCascadeRefresh = false,
        bool $isCascadeDetach = false,
        bool $isCascadeMerge = false,
        bool $orphanRemoval = false,
        public readonly string $storeAs = ClassMetadata::REFERENCE_STORE_AS_ID,
        public readonly array $prime = [],
    ) {
        if ($this->inversedBy && ($this->mappedBy || $this->repositoryMethod)) {
            throw new LogicException('A reference cannot be owning and inverse at the same time');
        }

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
            isCascadePersist: $isCascadePersist,
            isCascadeRemove: $isCascadeRemove,
            isCascadeRefresh: $isCascadeRefresh,
            isCascadeDetach: $isCascadeDetach,
            isCascadeMerge: $isCascadeMerge,
            orphanRemoval: $orphanRemoval,
        );

        $this->isInverseSide = $this->mappedBy || $this->repositoryMethod;
        $this->isOwningSide  = ! $this->isInverseSide;
        $this->reference     = true;
    }

    public static function fromMappingArray(array $mapping): ReferenceOneMapping|ReferenceManyMapping
    {
        $cascades = isset($mapping['cascade']) ? array_map('strtolower', (array) $mapping['cascade']) : [];

        if (in_array('all', $cascades) || isset($mapping['embedded'])) {
            $cascades = ['remove', 'persist', 'refresh', 'merge', 'detach'];
        }

        $mapping['cascade'] = $cascades;

        return $mapping['type'] === ClassMetadata::ONE
            ? ReferenceOneMapping::fromMappingArray($mapping)
            : ReferenceManyMapping::fromMappingArray($mapping);
    }

    public function getFieldName(string $pathPrefix = ''): string
    {
        if ($this->storeAs === ClassMetadata::REFERENCE_STORE_AS_ID) {
            return $pathPrefix;
        }

        return ($pathPrefix ? $pathPrefix . '.' : '') . $this->getFieldNamePrefix() . 'id';
    }

    public function getId(mixed $reference): mixed
    {
        return $this->storeAs === ClassMetadata::REFERENCE_STORE_AS_ID ? $reference : $reference[$this->getFieldName()];
    }

    private function getFieldNamePrefix(): string
    {
        if (! in_array($this->storeAs, [ClassMetadata::REFERENCE_STORE_AS_REF, ClassMetadata::REFERENCE_STORE_AS_DB_REF, ClassMetadata::REFERENCE_STORE_AS_DB_REF_WITH_DB])) {
            throw new LogicException('Can only get a reference prefix for DBRef and reference arrays');
        }

        return $this->storeAs === ClassMetadata::REFERENCE_STORE_AS_REF ? '' : '$';
    }
}
