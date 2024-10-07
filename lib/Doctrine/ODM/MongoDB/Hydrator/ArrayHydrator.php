<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Hydrator;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;

abstract class ArrayHydrator implements TypeMapHydrator
{
    public function __construct(protected DocumentManager $dm, protected ClassMetadata $class)
    {
    }

    public function getTypeMap(): array
    {
        return ['root' => 'array', 'array' => 'array', 'document' => 'array'];
    }

    public function prepareReadOptions(array $readOptions): array
    {
        return ['typeMap' => $this->getTypeMap()] + $readOptions;
    }

    abstract public function hydrate(object $document, ?array $data, array $hints = []): array;
}
