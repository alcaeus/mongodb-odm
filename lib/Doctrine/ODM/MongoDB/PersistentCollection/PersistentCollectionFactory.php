<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\PersistentCollection;

use Doctrine\Common\Collections\Collection as BaseCollection;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\AssociationMapping;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Mapping\FieldMapping;

/**
 * Interface for persistent collection classes factory.
 */
interface PersistentCollectionFactory
{
    /**
     * Creates specified persistent collection to work with given collection class.
     *
     * @psalm-param BaseCollection<array-key, object>|null $coll
     *
     * @psalm-return PersistentCollectionInterface<array-key, object>
     */
    public function create(DocumentManager $dm, AssociationMapping $mapping, ?BaseCollection $coll = null): PersistentCollectionInterface;
}
