<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Hydrator;

use Doctrine\ODM\MongoDB\UnitOfWork;

/**
 * The HydratorInterface defines methods all hydrator need to implement
 *
 * @psalm-import-type Hints from UnitOfWork
 */
interface HydratorInterface
{
    /**
     * Hydrate array of MongoDB document data into the given document object.
     *
     * @psalm-param Hints $hints
     *
     * @return array<string, mixed>
     */
    public function hydrate(object $document, null $data, array $hints = []): array;
}
