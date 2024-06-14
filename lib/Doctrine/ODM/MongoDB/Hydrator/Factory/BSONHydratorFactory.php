<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Hydrator\Factory;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Hydrator\BSONHydrator;
use Doctrine\ODM\MongoDB\Hydrator\Factory;
use Doctrine\ODM\MongoDB\Hydrator\HydratorInterface;
use Doctrine\ODM\MongoDB\Hydrator\TypeMapHydrator;
use Doctrine\ODM\MongoDB\PersistentCollection\PersistentCollectionFactory;

final class BSONHydratorFactory implements Factory, TypeMapHydrator
{
    /** @var list<HydratorInterface> */
    private array $hydrators = [];

    public function __construct(
        private DocumentManager $documentManager,
        private PersistentCollectionFactory $collectionFactory,
    ) {
    }

    public function getHydratorFor(string $className): BSONHydrator
    {
        if (! isset($this->hydrators[$className])) {
            // TODO: Cache resulting classes in files
            $this->hydrators[$className] = new BSONHydrator(
                $this->documentManager,
                $this->documentManager->getClassMetadata($className),
                $this->collectionFactory,
            );
        }

        return $this->hydrators[$className];
    }

    public function hasHydratorFor(string $className): bool
    {
        return isset($this->hydrators[$className]);
    }

    public function hydrate(object $document, mixed $data, array $hints = []): array
    {
        return $this->getHydratorFor($document::class)->hydrate($document, $data, $hints);
    }

    public function getTypeMap(): array
    {
        return ['root' => 'bson'];
    }

    public function prepareReadOptions(array $readOptions): array
    {
        return ['typeMap' => $this->getTypeMap()] + $readOptions;
    }
}
