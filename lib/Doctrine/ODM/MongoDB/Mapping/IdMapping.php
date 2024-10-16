<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Mapping;

use Doctrine\ODM\MongoDB\Types\Type;

/**
 * @internal
 * @phpstan-import-type FieldMappingConfig from ClassMetadata
 */
final class IdMapping extends TypedFieldMapping
{
    public readonly bool $id;

    public function __construct(
        ClassMetadata $owningDocument,
        string $fieldName,
        string $type = Type::ID,
        public string $generatorStrategy = 'auto',
        array $alsoLoadFields = [],
        public array $options = [],
    ) {
        $this->id = true;

        parent::__construct(
            owningDocument: $owningDocument,
            fieldName: $fieldName,
            name: '_id',
            type: $type,
            // TODO: Don't use strategy for generatorStrategy, they are different
            strategy: $this->generatorStrategy,
            alsoLoadFields: $alsoLoadFields,
        );
    }

    /** @phpstan-param FieldMappingConfig $mapping */
    public static function fromMappingArray(ClassMetadata $owningDocument, array $mapping): self
    {
        return new self(
            owningDocument: $owningDocument,
            fieldName: $mapping['fieldName'],
            type: $mapping['type'] ?? Type::ID,
            generatorStrategy: $mapping['strategy'] ?? 'auto',
            alsoLoadFields: $mapping['alsoLoadFields'] ?? [],
            options: $mapping['options'] ?? [],
        );
    }
}
