<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Tests\Mapping\Driver;

use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Mapping\EmbedManyMapping;
use Doctrine\ODM\MongoDB\Mapping\EmbedOneMapping;
use Doctrine\ODM\MongoDB\Mapping\ReferenceManyMapping;
use Doctrine\ODM\MongoDB\Mapping\ReferenceOneMapping;
use Doctrine\ODM\MongoDB\Tests\Mapping\AbstractMappingDriverTestCase;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Documents\Account;
use Documents\Address;
use Documents\Group;
use Documents\Phonenumber;
use Documents\Profile;
use PHPUnit\Framework\TestCase;
use TestDocuments\EmbeddedDocument;
use TestDocuments\NullableFieldsDocument;
use TestDocuments\PartialFilterDocument;
use TestDocuments\PrimedCollectionDocument;
use TestDocuments\QueryResultDocument;
use TestDocuments\User;

abstract class AbstractDriverTestCase extends TestCase
{
    /** @var MappingDriver|null */
    protected $driver;

    public function setUp(): void
    {
        // implement driver setup and metadata read
    }

    public function tearDown(): void
    {
        unset($this->driver);
    }

    public function testDriver(): void
    {
        $classMetadata = new ClassMetadata(User::class);
        $this->driver->loadMetadataForClass(User::class, $classMetadata);

        AbstractMappingDriverTestCase::assertMapping([
            'fieldName' => 'id',
            'id' => true,
            'name' => '_id',
            'type' => 'id',
            'nullable' => false,
        ], $classMetadata->fieldMappings['id']);

        AbstractMappingDriverTestCase::assertMapping([
            'fieldName' => 'username',
            'name' => 'username',
            'type' => 'string',
            'nullable' => false,
            'strategy' => ClassMetadata::STORAGE_STRATEGY_SET,
        ], $classMetadata->fieldMappings['username']);

        self::assertEquals([
            [
                'keys' => ['username' => 1],
                'options' => ['unique' => true, 'sparse' => true],
            ],
        ], $classMetadata->getIndexes());

        AbstractMappingDriverTestCase::assertMapping([
            'fieldName' => 'createdAt',
            'name' => 'createdAt',
            'type' => 'date',
            'nullable' => false,
            'strategy' => ClassMetadata::STORAGE_STRATEGY_SET,
        ], $classMetadata->fieldMappings['createdAt']);

        AbstractMappingDriverTestCase::assertMapping([
            'fieldName' => 'tags',
            'name' => 'tags',
            'type' => 'collection',
            'nullable' => false,
            'strategy' => ClassMetadata::STORAGE_STRATEGY_SET,
        ], $classMetadata->fieldMappings['tags']);

        AbstractMappingDriverTestCase::assertMapping([
            'fieldName' => 'address',
            'name' => 'address',
            'targetDocument' => Address::class,
            'isCascadeDetach' => true,
            'isCascadeMerge' => true,
            'isCascadePersist' => true,
            'isCascadeRefresh' => true,
            'isCascadeRemove' => true,
            'nullable' => false,
            'strategy' => ClassMetadata::STORAGE_STRATEGY_SET,
        ], $classMetadata->fieldMappings['address'], EmbedOneMapping::class);

        AbstractMappingDriverTestCase::assertMapping([
            'fieldName' => 'phonenumbers',
            'name' => 'phonenumbers',
            'targetDocument' => Phonenumber::class,
            'collectionClass' => null,
            'isCascadeDetach' => true,
            'isCascadeMerge' => true,
            'isCascadePersist' => true,
            'isCascadeRefresh' => true,
            'isCascadeRemove' => true,
            'nullable' => false,
            'strategy' => ClassMetadata::STORAGE_STRATEGY_PUSH_ALL,
            'storeEmptyArray' => false,
        ], $classMetadata->fieldMappings['phonenumbers'], EmbedManyMapping::class);

        AbstractMappingDriverTestCase::assertMapping([
            'fieldName' => 'profile',
            'name' => 'profile',
            'storeAs' => ClassMetadata::REFERENCE_STORE_AS_ID,
            'targetDocument' => Profile::class,
            'isCascadeDetach' => true,
            'isCascadeMerge' => true,
            'isCascadePersist' => true,
            'isCascadeRefresh' => true,
            'isCascadeRemove' => true,
            'isInverseSide' => false,
            'isOwningSide' => true,
            'nullable' => false,
            'strategy' => ClassMetadata::STORAGE_STRATEGY_SET,
            'inversedBy' => null,
            'mappedBy' => null,
            'repositoryMethod' => null,
            'orphanRemoval' => true,
            'prime' => [],
        ], $classMetadata->fieldMappings['profile'], ReferenceOneMapping::class);

        AbstractMappingDriverTestCase::assertMapping([
            'fieldName' => 'account',
            'name' => 'account',
            'storeAs' => ClassMetadata::REFERENCE_STORE_AS_DB_REF,
            'targetDocument' => Account::class,
            'isCascadeDetach' => true,
            'isCascadeMerge' => true,
            'isCascadePersist' => true,
            'isCascadeRefresh' => true,
            'isCascadeRemove' => true,
            'isInverseSide' => false,
            'isOwningSide' => true,
            'nullable' => false,
            'strategy' => ClassMetadata::STORAGE_STRATEGY_SET,
            'inversedBy' => null,
            'mappedBy' => null,
            'repositoryMethod' => null,
            'orphanRemoval' => false,
            'prime' => [],
        ], $classMetadata->fieldMappings['account'], ReferenceOneMapping::class);

        AbstractMappingDriverTestCase::assertMapping([
            'fieldName' => 'groups',
            'name' => 'groups',
            'storeAs' => ClassMetadata::REFERENCE_STORE_AS_DB_REF,
            'targetDocument' => Group::class,
            'collectionClass' => null,
            'isCascadeDetach' => true,
            'isCascadeMerge' => true,
            'isCascadePersist' => true,
            'isCascadeRefresh' => true,
            'isCascadeRemove' => true,
            'isInverseSide' => false,
            'isOwningSide' => true,
            'nullable' => false,
            'strategy' => ClassMetadata::STORAGE_STRATEGY_PUSH_ALL,
            'inversedBy' => null,
            'mappedBy' => null,
            'repositoryMethod' => null,
            'orphanRemoval' => false,
            'storeEmptyArray' => false,
        ], $classMetadata->fieldMappings['groups'], ReferenceManyMapping::class);

        self::assertEquals(
            [
                'postPersist' => ['doStuffOnPostPersist', 'doOtherStuffOnPostPersist'],
                'prePersist' => ['doStuffOnPrePersist'],
            ],
            $classMetadata->lifecycleCallbacks,
        );

        self::assertEquals(
            [
                'doStuffOnAlsoLoad' => ['unmappedField'],
            ],
            $classMetadata->alsoLoadMethods,
        );

        $classMetadata = new ClassMetadata(EmbeddedDocument::class);
        $this->driver->loadMetadataForClass(EmbeddedDocument::class, $classMetadata);

        AbstractMappingDriverTestCase::assertMapping([
            'fieldName' => 'name',
            'name' => 'name',
            'type' => 'string',
            'nullable' => false,
            'strategy' => ClassMetadata::STORAGE_STRATEGY_SET,
        ], $classMetadata->fieldMappings['name']);

        $classMetadata = new ClassMetadata(QueryResultDocument::class);
        $this->driver->loadMetadataForClass(QueryResultDocument::class, $classMetadata);

        AbstractMappingDriverTestCase::assertMapping([
            'fieldName' => 'name',
            'name' => 'name',
            'type' => 'string',
            'nullable' => false,
            'strategy' => ClassMetadata::STORAGE_STRATEGY_SET,
        ], $classMetadata->fieldMappings['name']);

        AbstractMappingDriverTestCase::assertMapping([
            'fieldName' => 'count',
            'name' => 'count',
            'type' => 'int',
            'nullable' => false,
            'strategy' => ClassMetadata::STORAGE_STRATEGY_SET,
        ], $classMetadata->fieldMappings['count']);
    }

    public function testPartialFilterExpressions(): void
    {
        $classMetadata = new ClassMetadata(PartialFilterDocument::class);
        $this->driver->loadMetadataForClass(PartialFilterDocument::class, $classMetadata);

        self::assertEquals([
            [
                'keys' => ['fieldA' => 1],
                'options' => [
                    'partialFilterExpression' => [
                        'version' => ['$gt' => 1],
                        'discr' => ['$eq' => 'default'],
                        'parent' => ['$eq' => null],
                    ],
                ],
            ],
            [
                'keys' => ['fieldB' => 1],
                'options' => [
                    'partialFilterExpression' => [
                        '$and' => [
                            ['version' => ['$gt' => 1]],
                            ['discr' => ['$eq' => 'default']],
                        ],
                    ],
                ],
            ],
            [
                'keys' => ['fieldC' => 1],
                'options' => [
                    'partialFilterExpression' => [
                        'embedded' => ['foo' => 'bar'],
                    ],
                ],
            ],
        ], $classMetadata->getIndexes());
    }

    public function testCollectionPrimers(): void
    {
        $classMetadata = new ClassMetadata(PrimedCollectionDocument::class);
        $this->driver->loadMetadataForClass(PrimedCollectionDocument::class, $classMetadata);

        AbstractMappingDriverTestCase::assertMapping([
            'fieldName' => 'references',
            'name' => 'references',
            'storeAs' => ClassMetadata::REFERENCE_STORE_AS_DB_REF,
            'targetDocument' => PrimedCollectionDocument::class,
            'collectionClass' => null,
            'isCascadeDetach' => false,
            'isCascadeMerge' => false,
            'isCascadePersist' => false,
            'isCascadeRefresh' => false,
            'isCascadeRemove' => false,
            'isInverseSide' => false,
            'isOwningSide' => true,
            'nullable' => false,
            'strategy' => ClassMetadata::STORAGE_STRATEGY_PUSH_ALL,
            'inversedBy' => null,
            'mappedBy' => null,
            'repositoryMethod' => null,
            'limit' => null,
            'skip' => null,
            'orphanRemoval' => false,
            'prime' => [],
            'storeEmptyArray' => true,
        ], $classMetadata->fieldMappings['references'], ReferenceManyMapping::class);

        AbstractMappingDriverTestCase::assertMapping([
            'fieldName' => 'inverseMappedBy',
            'name' => 'inverseMappedBy',
            'storeAs' => ClassMetadata::REFERENCE_STORE_AS_DB_REF,
            'targetDocument' => PrimedCollectionDocument::class,
            'collectionClass' => null,
            'isCascadeDetach' => false,
            'isCascadeMerge' => false,
            'isCascadePersist' => false,
            'isCascadeRefresh' => false,
            'isCascadeRemove' => false,
            'isInverseSide' => true,
            'isOwningSide' => false,
            'nullable' => false,
            'strategy' => ClassMetadata::STORAGE_STRATEGY_PUSH_ALL,
            'inversedBy' => null,
            'mappedBy' => 'references',
            'repositoryMethod' => null,
            'limit' => null,
            'skip' => null,
            'orphanRemoval' => false,
            'prime' => ['references'],
            'storeEmptyArray' => false,
        ], $classMetadata->fieldMappings['inverseMappedBy'], ReferenceManyMapping::class);
    }

    public function testNullableFieldsMapping(): void
    {
        $classMetadata = new ClassMetadata(NullableFieldsDocument::class);
        $this->driver->loadMetadataForClass(NullableFieldsDocument::class, $classMetadata);

        AbstractMappingDriverTestCase::assertMapping([
            'fieldName' => 'username',
            'name' => 'username',
            'type' => 'string',
            'nullable' => true,
            'strategy' => ClassMetadata::STORAGE_STRATEGY_SET,
        ], $classMetadata->fieldMappings['username']);

        AbstractMappingDriverTestCase::assertMapping([
            'fieldName' => 'address',
            'name' => 'address',
            'targetDocument' => Address::class,
            'isCascadeDetach' => true,
            'isCascadeMerge' => true,
            'isCascadePersist' => true,
            'isCascadeRefresh' => true,
            'isCascadeRemove' => true,
            'nullable' => true,
            'strategy' => ClassMetadata::STORAGE_STRATEGY_SET,
        ], $classMetadata->fieldMappings['address'], EmbedOneMapping::class);

        AbstractMappingDriverTestCase::assertMapping([
            'fieldName' => 'phonenumbers',
            'name' => 'phonenumbers',
            'targetDocument' => Phonenumber::class,
            'collectionClass' => null,
            'isCascadeDetach' => true,
            'isCascadeMerge' => true,
            'isCascadePersist' => true,
            'isCascadeRefresh' => true,
            'isCascadeRemove' => true,
            'nullable' => true,
            'strategy' => ClassMetadata::STORAGE_STRATEGY_PUSH_ALL,
            'storeEmptyArray' => false,
        ], $classMetadata->fieldMappings['phonenumbers'], EmbedManyMapping::class);

        AbstractMappingDriverTestCase::assertMapping([
            'fieldName' => 'profile',
            'name' => 'profile',
            'storeAs' => ClassMetadata::REFERENCE_STORE_AS_DB_REF,
            'targetDocument' => Profile::class,
            'isCascadeDetach' => false,
            'isCascadeMerge' => false,
            'isCascadePersist' => false,
            'isCascadeRefresh' => false,
            'isCascadeRemove' => false,
            'isInverseSide' => false,
            'isOwningSide' => true,
            'nullable' => true,
            'strategy' => ClassMetadata::STORAGE_STRATEGY_SET,
            'inversedBy' => null,
            'mappedBy' => null,
            'repositoryMethod' => null,
            'orphanRemoval' => false,
            'prime' => [],
        ], $classMetadata->fieldMappings['profile'], ReferenceOneMapping::class);

        AbstractMappingDriverTestCase::assertMapping([
            'fieldName' => 'groups',
            'name' => 'groups',
            'storeAs' => ClassMetadata::REFERENCE_STORE_AS_DB_REF,
            'targetDocument' => Group::class,
            'collectionClass' => null,
            'isCascadeDetach' => false,
            'isCascadeMerge' => false,
            'isCascadePersist' => false,
            'isCascadeRefresh' => false,
            'isCascadeRemove' => false,
            'isInverseSide' => false,
            'isOwningSide' => true,
            'nullable' => true,
            'strategy' => ClassMetadata::STORAGE_STRATEGY_PUSH_ALL,
            'inversedBy' => null,
            'mappedBy' => null,
            'repositoryMethod' => null,
            'limit' => null,
            'skip' => null,
            'orphanRemoval' => false,
            'prime' => [],
            'storeEmptyArray' => false,
        ], $classMetadata->fieldMappings['groups'], ReferenceManyMapping::class);
    }
}
