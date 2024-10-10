<?php

declare(strict_types=1);

namespace Documents\Mapping;

use Doctrine\ODM\MongoDB\Mapping\FieldMapping;
use Doctrine\ODM\MongoDB\Types\Type;
use PHPUnit\Framework\TestCase;

final class FieldMappingTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $this->markTestIncomplete('Needs rewriting');
        $fieldMapping = new FieldMapping('foo');

        self::assertSame('foo', $fieldMapping->fieldName);
        self::assertSame('foo', $fieldMapping->name, 'name defaults to field name if not given');
        self::assertSame(Type::STRING, $fieldMapping->type, 'The type defaults to string if not given');
    }

    public function testDifferentName(): void
    {
        $this->markTestIncomplete('Needs rewriting');
        $fieldMapping = new FieldMapping('foo', 'bar');

        self::assertSame('foo', $fieldMapping->fieldName);
        self::assertSame('bar', $fieldMapping->name);
    }

    public function testDifferentType(): void
    {
        $this->markTestIncomplete('Needs rewriting');
        $fieldMapping = new FieldMapping('foo', type: Type::BOOL);

        self::assertSame('foo', $fieldMapping->fieldName);
        self::assertSame(Type::BOOL, $fieldMapping->type);
    }
}
