<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Hydrator;

use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\UnitOfWork;

/**
 * The HydratorFactory class is responsible for instantiating a correct hydrator
 * type based on document's ClassMetadata
 *
 * @psalm-import-type Hints from UnitOfWork
 */
interface HydratorFactoryInterface
{
    /**
     * Gets the hydrator object for the given document class.
     *
     * @psalm-param class-string $className
     */
    public function getHydratorFor(string $className): HydratorInterface;

    /**
     * Generates hydrator classes for all given classes.
     *
     * @param ClassMetadata<object>[] $classes The classes (ClassMetadata instances) for which to generate hydrators.
     * @param string|null             $toDir   The target directory of the hydrator classes. If not specified, the
     *                                                  directory configured on the Configuration of the DocumentManager used
     *                                                  by this factory is used.
     */
    public function generateHydratorClasses(array $classes, ?string $toDir = null): void;

    /**
     * Hydrate array of MongoDB document data into the given document object.
     *
     * @param array<string, mixed> $data
     * @psalm-param Hints $hints Any hints to account for during reconstitution/lookup of the document.
     *
     * @return array<string, mixed>
     */
    public function hydrate(object $document, array $data, array $hints = []): array;
}
