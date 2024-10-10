<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Mapping;

use Doctrine\ODM\MongoDB\Types\Type;

final class IdMapping extends TypedFieldMapping
{
    public readonly bool $id;

    public function __construct(
        string $fieldName,
        string $type = Type::ID,
        public string $generatorStrategy = 'auto',
        array $alsoLoadFields = [],
        public array $options = [],
    ) {
        $this->id = true;

        parent::__construct(
            fieldName: $fieldName,
            name: '_id',
            type: $type,
            // TODO: Don't use strategy for generatorStrategy, they are different
            strategy: $this->generatorStrategy,
            alsoLoadFields: $alsoLoadFields,
        );
    }

    public static function fromMappingArray(array $mapping): self
    {
        return new self(
            fieldName: $mapping['fieldName'],
            type: $mapping['type'] ?? Type::ID,
            generatorStrategy: $mapping['strategy'] ?? 'auto',
            alsoLoadFields: $mapping['alsoLoadFields'] ?? [],
            options: $mapping['options'] ?? [],
        );
    }
}
