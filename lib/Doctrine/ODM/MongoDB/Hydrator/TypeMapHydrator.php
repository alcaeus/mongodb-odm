<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Hydrator;

use Doctrine\ODM\MongoDB\UnitOfWork;

/** @psalm-import-type ReadOptions from UnitOfWork */
interface TypeMapHydrator extends HydratorInterface
{
    public function getTypeMap(): array;

    /**
     * @psalm-param ReadOptions $options
     *
     * @psalm-return ReadOptions
     */
    public function prepareReadOptions(array $readOptions): array;
}
