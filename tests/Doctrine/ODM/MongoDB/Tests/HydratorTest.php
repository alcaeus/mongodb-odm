<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Tests;

use DateTime;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Hydrator\HydratorException;
use Doctrine\ODM\MongoDB\Hydrator\HydratorInterface;
use Doctrine\ODM\MongoDB\Hydrator\TypeMapHydrator;
use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
use Doctrine\ODM\MongoDB\PersistentCollection;
use Doctrine\ODM\MongoDB\PersistentCollection\PersistentCollectionInterface;
use Doctrine\ODM\MongoDB\Query\Query;
use MongoDB\BSON\Document;
use MongoDB\BSON\PackedArray;
use MongoDB\BSON\UTCDateTime;
use ProxyManager\Proxy\GhostObjectInterface;

class HydratorTest extends BaseTestCase
{
    public function testHydrator(): void
    {
        $user     = new HydrationClosureUser();
        $hydrator = $this->dm->getHydratorFactory()->getHydratorFor($user::class);

        $hydrator->hydrate($user, $this->getHydrationData([
            '_id' => 1,
            'title' => null,
            'name' => 'jon',
            'birthdate' => new UTCDateTime(new DateTime('1961-01-01')),
            'referenceOne' => ['$id' => '1'],
            'referenceMany' => [
                ['$id' => '1'],
                ['$id' => '2'],
            ],
            'embedOne' => ['name' => 'jon'],
            'embedMany' => [
                ['name' => 'jon'],
            ],
        ], $hydrator));

        self::assertEquals(1, $user->id);
        self::assertNull($user->title);
        self::assertEquals('jon', $user->name);
        self::assertInstanceOf(DateTime::class, $user->birthdate);
        self::assertInstanceOf(HydrationClosureReferenceOne::class, $user->referenceOne);
        self::assertInstanceOf(GhostObjectInterface::class, $user->referenceOne);
        self::assertInstanceOf(PersistentCollection::class, $user->referenceMany);
        self::assertInstanceOf(GhostObjectInterface::class, $user->referenceMany[0]);
        self::assertInstanceOf(GhostObjectInterface::class, $user->referenceMany[1]);
        self::assertInstanceOf(HydrationClosureEmbedOne::class, $user->embedOne);
        self::assertInstanceOf(PersistentCollection::class, $user->embedMany);
        self::assertEquals('jon', $user->embedOne->name);
        self::assertEquals('jon', $user->embedMany[0]->name);
    }

    public function testHydrateProxyWithMissingAssociations(): void
    {
        $user = $this->dm->getReference(HydrationClosureUser::class, 1);
        self::assertInstanceOf(GhostObjectInterface::class, $user);

        $hydrator = $this->dm->getHydratorFactory()->getHydratorFor(HydrationClosureUser::class);

        $hydrator->hydrate($user, $this->getHydrationData([
            '_id' => 1,
            'title' => null,
            'name' => 'jon',
        ], $hydrator));

        self::assertEquals(1, $user->id);
        self::assertNull($user->title);
        self::assertEquals('jon', $user->name);
        self::assertNull($user->birthdate);
        self::assertNull($user->referenceOne);
        self::assertInstanceOf(PersistentCollection::class, $user->referenceMany);
        self::assertNull($user->embedOne);
        self::assertInstanceOf(PersistentCollection::class, $user->embedMany);
    }

    public function testReadOnly(): void
    {
        $user     = new HydrationClosureUser();
        $hydrator = $this->dm->getHydratorFactory()->getHydratorFor($user::class);

        $hydrator->hydrate($user, $this->getHydrationData([
            '_id' => 1,
            'name' => 'maciej',
            'birthdate' => new UTCDateTime(new DateTime('1961-01-01')),
            'embedOne' => ['name' => 'maciej'],
            'embedMany' => [
                ['name' => 'maciej'],
            ],
        ], $hydrator), [Query::HINT_READ_ONLY => true]);

        self::assertFalse($this->uow->isInIdentityMap($user));
        self::assertFalse($this->uow->isInIdentityMap($user->embedOne));
        self::assertFalse($this->uow->isInIdentityMap($user->embedMany[0]));
    }

    public function testEmbedOneWithWrongType(): void
    {
        $user     = new HydrationClosureUser();
        $hydrator = $this->dm->getHydratorFactory()->getHydratorFor($user::class);

        $this->expectExceptionObject(HydratorException::associationTypeMismatch(HydrationClosureUser::class, 'embedOne', Document::class, 'string'));

        $hydrator->hydrate($user, $this->getHydrationData([
            '_id' => 1,
            'embedOne' => 'jon',
        ], $hydrator));
    }

    public function testEmbedManyWithWrongType(): void
    {
        $user     = new HydrationClosureUser();
        $hydrator = $this->dm->getHydratorFactory()->getHydratorFor($user::class);

        $this->expectExceptionObject(HydratorException::associationTypeMismatch(HydrationClosureUser::class, 'embedMany', PackedArray::class . ' or ' . Document::class, 'string'));

        $hydrator->hydrate($user, $this->getHydrationData([
            '_id' => 1,
            'embedMany' => 'jon',
        ], $hydrator));
    }

    public function testEmbedManyWithWrongElementType(): void
    {
        $user     = new HydrationClosureUser();
        $hydrator = $this->dm->getHydratorFactory()->getHydratorFor($user::class);

        $hydrator->hydrate($user, $this->getHydrationData([
            '_id' => 1,
            'embedMany' => ['jon'],
        ], $hydrator));

        self::assertInstanceOf(PersistentCollectionInterface::class, $user->embedMany);

        $this->expectExceptionObject(HydratorException::associationItemTypeMismatch(HydrationClosureUser::class, 'embedMany', 0, 'array or object', 'string'));

        $user->embedMany->initialize();
    }

    public function testReferenceOneWithWrongType(): void
    {
        $user     = new HydrationClosureUser();
        $hydrator = $this->dm->getHydratorFactory()->getHydratorFor($user::class);

        $this->expectExceptionObject(HydratorException::associationTypeMismatch(HydrationClosureUser::class, 'referenceOne', Document::class, 'string'));

        $hydrator->hydrate($user, $this->getHydrationData([
            '_id' => 1,
            'referenceOne' => 'jon',
        ], $hydrator));
    }

    public function testReferenceManyWithWrongType(): void
    {
        $user     = new HydrationClosureUser();
        $hydrator = $this->dm->getHydratorFactory()->getHydratorFor($user::class);

        $this->expectExceptionObject(HydratorException::associationTypeMismatch(HydrationClosureUser::class, 'referenceMany', PackedArray::class . ' or ' . Document::class, 'string'));

        $hydrator->hydrate($user, $this->getHydrationData([
            '_id' => 1,
            'referenceMany' => 'jon',
        ], $hydrator));
    }

    public function testReferenceManyWithWrongElementType(): void
    {
        $user     = new HydrationClosureUser();
        $hydrator = $this->dm->getHydratorFactory()->getHydratorFor($user::class);

        $hydrator->hydrate($user, $this->getHydrationData([
            '_id' => 1,
            'referenceMany' => ['jon'],
        ], $hydrator));

        self::assertInstanceOf(PersistentCollectionInterface::class, $user->referenceMany);

        $this->expectExceptionObject(HydratorException::associationItemTypeMismatch(HydrationClosureUser::class, 'referenceMany', 0, 'array or object', 'string'));

        $user->referenceMany->initialize();
    }

    private function getHydrationData(array $data, HydratorInterface $hydrator): mixed
    {
        if ($hydrator instanceof TypeMapHydrator) {
            return Document::fromPHP($data)->toPHP($hydrator->getTypeMap());
        }

        return $data;
    }
}

#[ODM\Document]
class HydrationClosureUser
{
    /** @var string|null */
    #[ODM\Id]
    public $id;

    /** @var string|null */
    #[ODM\Field(type: 'string', nullable: true)]
    public $title = 'Mr.';

    /** @var string|null */
    #[ODM\Field(type: 'string')]
    public $name;

    /** @var DateTime|null */
    #[ODM\Field(type: 'date')]
    public $birthdate;

    /** @var HydrationClosureReferenceOne|null */
    #[ODM\ReferenceOne(targetDocument: HydrationClosureReferenceOne::class)]
    public $referenceOne;

    /** @var Collection<int, HydrationClosureReferenceMany>|array<HydrationClosureReferenceMany> */
    #[ODM\ReferenceMany(targetDocument: HydrationClosureReferenceMany::class)]
    public $referenceMany = [];

    /** @var HydrationClosureEmbedOne|null */
    #[ODM\EmbedOne(targetDocument: HydrationClosureEmbedOne::class)]
    public $embedOne;

    /** @var Collection<int, HydrationClosureEmbedMany>|array<HydrationClosureReferenceMany> */
    #[ODM\EmbedMany(targetDocument: HydrationClosureEmbedMany::class)]
    public $embedMany = [];
}

#[ODM\Document]
class HydrationClosureReferenceOne
{
    /** @var string|null */
    #[ODM\Id]
    public $id;

    /** @var string|null */
    #[ODM\Field(type: 'string')]
    public $name;
}

#[ODM\Document]
class HydrationClosureReferenceMany
{
    /** @var string|null */
    #[ODM\Id]
    public $id;

    /** @var string|null */
    #[ODM\Field(type: 'string')]
    public $name;
}

#[ODM\EmbeddedDocument]
class HydrationClosureEmbedMany
{
    /** @var string|null */
    #[ODM\Field(type: 'string')]
    public $name;
}

#[ODM\EmbeddedDocument]
class HydrationClosureEmbedOne
{
    /** @var string|null */
    #[ODM\Field(type: 'string')]
    public $name;
}
