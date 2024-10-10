<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Mapping;

use BackedEnum;
use Doctrine\ODM\MongoDB\Types\Type;

/** @internal */
class EnumFieldMapping extends TypedFieldMapping
{
    /** @param class-string<BackedEnum>|null $enumType */
    public function __construct(
        ClassMetadata $owningDocument,
        string $fieldName,
        ?string $name,
        ?string $type = Type::STRING,
        bool $nullable = false,
        bool $notSaved = false,
        array $alsoLoadFields = [],
        public readonly ?string $enumType = null,
    ) {
        parent::__construct(
            owningDocument: $owningDocument,
            fieldName: $fieldName,
            name: $name,
            type: $type,
            nullable: $nullable,
            notSaved: $notSaved,
            alsoLoadFields: $alsoLoadFields,
        );
    }

    public static function fromMappingArray(ClassMetadata $owningDocument, array $mapping): self
    {
        return new EnumFieldMapping(
            owningDocument: $owningDocument,
            fieldName: $mapping['fieldName'],
            name: $mapping['name'],
            type: $mapping['type'],
            nullable: $mapping['nullable'] ?? false,
            notSaved: $mapping['notSaved'] ?? false,
            alsoLoadFields: $mapping['alsoLoadFields'] ?? [],
            enumType: $mapping['enumType'],
        );
    }
}
