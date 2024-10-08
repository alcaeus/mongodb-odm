<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Hydrator;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\PersistentCollection\PersistentCollectionFactory;
use Doctrine\ODM\MongoDB\PersistentCollection\PersistentCollectionInterface;
use Doctrine\ODM\MongoDB\Query\Query;
use Doctrine\ODM\MongoDB\Types\DateType;
use Doctrine\ODM\MongoDB\Types\Type;
use Doctrine\ODM\MongoDB\Utility\LifecycleEventManager;
use MongoDB\BSON\Document;
use MongoDB\BSON\PackedArray;
use ProxyManager\Proxy\GhostObjectInterface;
use UnexpectedValueException;

use function array_merge;
use function call_user_func;
use function get_debug_type;
use function gettype;
use function sprintf;

final class BSONHydrator implements TypeMapHydrator
{
    private readonly Type $fallbackType;

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ClassMetadata $classMetadata,
        private readonly Factory $hydratorFactory,
        private readonly LifecycleEventManager $eventManager,
        private readonly PersistentCollectionFactory $collectionFactory,
    ) {
        $this->fallbackType = Type::getType('raw');
    }

    public function hydrate(object $document, ?Document $data, array $hints = []): array
    {
        // TODO: Events handlers are currently not able to change data
        $this->eventManager->preLoad($this->classMetadata, $document, $data);

        $hydratedData = [];

        if (! empty($this->classMetadata->alsoLoadMethods)) {
            foreach ($this->classMetadata->alsoLoadMethods as $method => $fieldNames) {
                foreach ($fieldNames as $fieldName) {
                    // Invoke the method only once for the first field we find
                    if ($data->has($fieldName)) {
                        $document->$method($data->get($fieldName));
                        continue 2;
                    }
                }
            }
        }

        if ($document instanceof GhostObjectInterface && $document->getProxyInitializer() !== null) {
            // Inject an empty initialiser to not load any object data
            $document->setProxyInitializer(static function (
                GhostObjectInterface $ghostObject,
                string $method, // we don't care
                array $parameters, // we don't care
                &$initializer,
                array $properties, // we currently do not use this
            ): bool {
                $initializer = null;

                return true;
            });
        }

        foreach ($this->classMetadata->fieldMappings as $fieldName => $mapping) {
            $documentFieldName  = $mapping['name'];
            $documentFieldValue = null;
            $hasField           = false;

            if ($data->has($documentFieldName)) {
                $hasField           = true;
                $documentFieldValue = $data->get($documentFieldName);
            } elseif (isset($mapping['alsoLoadFields'])) {
                foreach ($mapping['alsoLoadFields'] as $alsoLoadField) {
                    if ($data->has($alsoLoadField)) {
                        $hasField           = true;
                        $documentFieldValue = $data->get($alsoLoadField);
                        break;
                    }
                }
            }

            if (! $hasField && empty($mapping['association'])) {
                continue;
            }

            $fieldValue    = null;
            $hydratedValue = null;
            if (! empty($mapping['association'])) {
                $fieldValue = $this->hydrateAssociation($document, $data, $fieldName, $documentFieldValue, $mapping, $hints);
//            } elseif ($documentFieldValue === null) {
//                // TODO: Do we need to consider $mapping['nullable'] here?
//                continue;
            } else {
                $type       = Type::hasType($mapping['type']) ? Type::getType($mapping['type']) : $this->fallbackType;
                $fieldValue = $type->convertToPHPValue($documentFieldValue);

                // For dates, ensure that we're storing a different value in $hydratedData than in the document
                // This is to ensure that changes to the instance stored in the document don't apply to $hydratedData
                // at the same time, breaking change tracking
                // TODO: This is broken behaviour and needs changing
                if ($type instanceof DateType) {
                    $hydratedValue = clone $fieldValue;
                }
            }

            $this->classMetadata->reflFields[$fieldName]->setValue($document, $fieldValue);
            $hydratedData[$fieldName] = $hydratedValue ?? $fieldValue;
        }

        $this->eventManager->postLoad($this->classMetadata, $document);

        return $hydratedData;
    }

    private function hydrateAssociation(object $document, ?Document $data, string $fieldName, mixed $value, array $mapping, array $hints = []): mixed
    {
        switch ($mapping['association']) {
            case ClassMetadata::EMBED_MANY:
            case ClassMetadata::REFERENCE_MANY:
                // All many relationships are handled by a PersistentCollection
                return $this->hydratePersistentCollection($document, $fieldName, $value, $mapping, $hints);

            case ClassMetadata::EMBED_ONE:
                return $this->hydrateEmbedOne($document, $fieldName, $value, $mapping, $hints);

            case ClassMetadata::REFERENCE_ONE:
                return $mapping['isInverseSide']
                    ? $this->hydrateInverseReferenceOne($document, $fieldName, $data->get('_id'), $mapping)
                    : $this->hydrateReferenceOne($document, $fieldName, $value, $mapping);

            default:
                throw new UnexpectedValueException(sprintf('Unknown association mapping type "%s" for field "%s" in class "%s".', $mapping['association'], $fieldName, $this->classMetadata->name));
        }
    }

    public function getTypeMap(): array
    {
        return ['root' => 'bson'];
    }

    public function prepareReadOptions(array $readOptions): array
    {
        return ['typeMap' => $this->getTypeMap()] + $readOptions;
    }

    private function hydratePersistentCollection(object $document, string $fieldName, mixed $value, array $mapping, array $hints): PersistentCollectionInterface
    {
        $collection = $this->collectionFactory->create($this->documentManager, $mapping);
        $collection->setHints($hints);
        $collection->setOwner($document, $mapping);
        $collection->setInitialized(false);

        // TODO: Use lazy BSON evaluation
        if ($value instanceof PackedArray || $value instanceof Document) {
            $collection->setMongoData($value->toPHP(['root' => 'array', 'document' => 'bson']));
        } elseif ($value !== null) {
            throw HydratorException::associationTypeMismatch(
                $document::class,
                $fieldName,
                sprintf('%s or %s', PackedArray::class, Document::class),
                get_debug_type($value),
            );
        }

        return $collection;
    }

    private function hydrateEmbedOne(object $document, string $fieldName, mixed $value, array $mapping, array $hints): ?object
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof Document) {
            throw HydratorException::associationTypeMismatch($document::class, $fieldName, Document::class, gettype($value));
        }

        $className        = $this->documentManager->getClassNameForAssociation($mapping, $value);
        $embeddedMetadata = $this->documentManager->getClassMetadata($className);
        $embeddedDocument = $embeddedMetadata->newInstance();

        $this->documentManager->getUnitOfWork()->setParentAssociation($embeddedDocument, $mapping, $document, '%1$s');

        $embeddedData = $this->hydratorFactory->hydrate($embeddedDocument, $value, $hints);
        $embeddedId   = $embeddedMetadata->identifier && isset($embeddedData[$embeddedMetadata->identifier]) ? $embeddedData[$embeddedMetadata->identifier] : null;

        // TODO: extract; this shouldn't really be responsibility of the hydrator
        if (empty($hints[Query::HINT_READ_ONLY])) {
            $this->documentManager->getUnitOfWork()->registerManaged($embeddedDocument, $embeddedId, $embeddedData);
        }

        return $embeddedDocument;
    }

    private function hydrateInverseReferenceOne(object $document, string $fieldName, mixed $identifier, array $mapping): object|null
    {
        $fieldMapping = $this->classMetadata->fieldMappings[$fieldName];
        $className    = $fieldMapping['targetDocument'];

        if (isset($mapping['repositoryMethod']) && $mapping['repositoryMethod']) {
            $repository = $this->documentManager->getRepository($className);

            return call_user_func([$repository, $mapping['repositoryMethod']], $document);
        }

        $targetClass       = $this->documentManager->getClassMetadata($className);
        $mappedByMapping   = $targetClass->fieldMappings[$mapping['mappedBy']];
        $mappedByFieldName = ClassMetadata::getReferenceFieldName($mappedByMapping['storeAs'], $mapping['mappedBy']);

        return $this->documentManager->getUnitOfWork()->getDocumentPersister($className)->load(
            array_merge(
                [$mappedByFieldName => $identifier],
                $fieldMapping['criteria'] ?? [],
            ),
            null,
            [],
            0,
            $fieldMapping['sort'] ?? [],
        );
    }

    private function hydrateReferenceOne(object $document, string $fieldName, mixed $value, array $mapping): object|null
    {
        if ($value === null) {
            return null;
        }

        if ($mapping['storeAs'] !== ClassMetadata::REFERENCE_STORE_AS_ID && ! $value instanceof Document) {
            throw HydratorException::associationTypeMismatch($document::class, $fieldName, Document::class, gettype($value));
        }

        $className      = $this->documentManager->getClassNameForAssociation($mapping, $value);
        $identifier     = ClassMetadata::getReferenceId($value, $mapping['storeAs']);
        $targetMetadata = $this->documentManager->getClassMetadata($className);
        $id             = $targetMetadata->getPHPIdentifierValue($identifier);

        return $this->documentManager->getReference($className, $id);
    }
}
