<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Mapping;

use BackedEnum;
use Doctrine\ODM\MongoDB\Types\Type;

/**
 * @internal
 * @phpstan-import-type FieldMappingConfig from ClassMetadata
 */
class TypedFieldMapping extends FieldMapping
{
    /** @param class-string<BackedEnum>|null $enumType */
    public function __construct(
        ClassMetadata $owningDocument,
        string $fieldName,
        ?string $name,
        public readonly ?string $type = Type::STRING,
        bool $nullable = false,
        bool $notSaved = false,
        public readonly bool $version = false,
        public readonly bool $lock = false,
        string $strategy = ClassMetadata::STORAGE_STRATEGY_SET,
        array $alsoLoadFields = [],
    ) {
        parent::__construct(
            owningDocument: $owningDocument,
            fieldName: $fieldName,
            name: $name,
            nullable: $nullable,
            notSaved: $notSaved || $version || $lock,
            strategy: $strategy,
            alsoLoadFields: $alsoLoadFields,
        );
    }

    /** @phpstan-param FieldMappingConfig $mapping */
    public static function fromMappingArray(ClassMetadata $owningDocument, array $mapping): self
    {
        return match (true) {
            isset($mapping['id']) => IdMapping::fromMappingArray($owningDocument, $mapping),
            isset($mapping['enumType']) => EnumFieldMapping::fromMappingArray($owningDocument, $mapping),
            default => new self(
                owningDocument: $owningDocument,
                fieldName: $mapping['fieldName'],
                name: $mapping['name'],
                type: $mapping['type'],
                nullable: $mapping['nullable'] ?? false,
                notSaved: $mapping['notSaved'] ?? false,
                version: $mapping['version'] ?? false,
                lock: $mapping['lock'] ?? false,
                strategy: $mapping['strategy'] ?? ClassMetadata::STORAGE_STRATEGY_SET,
                alsoLoadFields: $mapping['alsoLoadFields'] ?? [],
            )
        };
    }
}
