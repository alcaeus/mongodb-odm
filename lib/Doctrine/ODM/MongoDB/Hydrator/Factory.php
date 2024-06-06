<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Hydrator;

use Doctrine\ODM\MongoDB\UnitOfWork;

/** @psalm-import-type Hints from UnitOfWork */
interface Factory extends HydratorInterface
{
    /**
     * Checks if this factory knows a hydrator for the class.
     *
     * @psalm-param class-string $className
     */
    public function hasHydratorFor(string $className): bool;

    /**
     * Gets the hydrator object for the given document class.
     *
     * @psalm-param class-string $className
     */
    public function getHydratorFor(string $className): HydratorInterface;

    /**
     * Hydrate any data into an object, if you can
     *
     * @psalm-param Hints $hints Any hints to account for during reconstitution/lookup of the document.
     */
    public function hydrate(object $document, mixed $data, array $hints = []): array;
}
