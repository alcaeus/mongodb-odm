<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Hydrator;

use Doctrine\Common\EventManager;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Hydrator\Factory\ArrayHydratorFactory;
use Doctrine\ODM\MongoDB\Utility\LifecycleEventManager;

/** @deprecated Deprecated, use ArrayHydratorFactory::class instead */
final class HydratorFactory extends ArrayHydratorFactory
{
    public function __construct(DocumentManager $dm, EventManager $evm, ?string $hydratorDir, ?string $hydratorNs, int $autoGenerate)
    {
        parent::__construct(
            $dm,
            new LifecycleEventManager($dm, $evm),
            $hydratorDir,
            $hydratorNs,
            $autoGenerate,
        );
    }
}
