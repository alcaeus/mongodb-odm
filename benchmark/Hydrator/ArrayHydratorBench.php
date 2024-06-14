<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Benchmark\Hydrator;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\Document;

final class ArrayHydratorBench extends AbstractHydrateDocumentBench
{
    protected function useBSONHydrator(): bool
    {
        return false;
    }

    protected static function prepareDataset(array $dataset): array
    {
        return array_map(
            static fn (Document $document) => $document->toPHP(DocumentManager::CLIENT_TYPEMAP),
            $dataset,
        );
    }
}
