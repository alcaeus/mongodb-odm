<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Benchmark\Hydrator;

final class BSONHydratorBench extends AbstractHydrateDocumentBench
{
    protected function useBSONHydrator(): bool
    {
        return true;
    }

    protected static function prepareDataset(array $dataset): array
    {
        return $dataset;
    }
}
