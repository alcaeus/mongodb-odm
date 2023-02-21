<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Hydrator;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\PersistentCollection\PersistentCollectionFactory;
use Doctrine\ODM\MongoDB\Query\Query;
use Doctrine\ODM\MongoDB\Types\Type;
use MongoDB\BSON\Document;
use MongoDB\BSON\PackedArray;
use ProxyManager\Proxy\GhostObjectInterface;
use UnexpectedValueException;

use function get_debug_type;
use function gettype;
use function sprintf;

final class BSONHydrator implements TypeMapHydrator
{
    private readonly Type $fallbackType;

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ClassMetadata $classMetadata,
        private readonly PersistentCollectionFactory $collectionFactory,
    ) {
        $this->fallbackType = Type::getType('raw');
    }

    public function hydrate(object $document, ?Document $data, array $hints = []): array
    {
        // TODO: Events
        $hydratedData = [];

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
            $documentFieldName = $mapping['name'];
            if (! $data->has($documentFieldName)) {
                if (empty($mapping['association'])) {
                    continue;
                }

                $documentFieldValue = null;
            } else {
                $documentFieldValue = $data->get($documentFieldName);
            }

//            if ($documentFieldValue === null && !$mapping['nullable']) {
//                // TODO: error?
//                continue;
//            }

            $fieldValue = null;
            if (! empty($mapping['association'])) {
                $fieldValue = $this->hydrateAssociation($document, $data, $fieldName, $documentFieldValue, $mapping, $hints);
            } else {
                $type       = Type::hasType($mapping['type']) ? Type::getType($mapping['type']) : $this->fallbackType;
                $fieldValue = $type->convertToPHPValue($documentFieldValue);
            }

            $this->classMetadata->reflFields[$fieldName]->setValue($document, $fieldValue);
            $hydratedData[$fieldName] = $fieldValue;
        }

        return $hydratedData;
    }

    private function hydrateAssociation(object $document, ?Document $data, string $fieldName, mixed $value, array $mapping, array $hints = []): mixed
    {
        switch ($mapping['association']) {
            case ClassMetadata::EMBED_MANY:
            case ClassMetadata::REFERENCE_MANY:
                // All many relationships are handled by a PersistentCollection
                $collection = $this->collectionFactory->create($this->documentManager, $mapping);
                $collection->setHints($hints);
                $collection->setOwner($document, $mapping);
                $collection->setInitialized(false);

                // TODO: Use lazy BSON evaluation
                if ($value instanceof PackedArray || $value instanceof Document) {
                    $collection->setMongoData($value->toPHP(['document' => 'bson']));
                } elseif ($value !== null) {
                    throw HydratorException::associationTypeMismatch(
                        $document::class,
                        $fieldName,
                        sprintf('%s or %s', PackedArray::class, Document::class),
                        get_debug_type($value),
                    );
                }

                return $collection;

            case ClassMetadata::EMBED_ONE:
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

                // TODO: don't rely on document manager to provide factory
                $embeddedData = $this->documentManager->getHydratorFactory()->hydrate($embeddedDocument, $value, $hints);
                $embeddedId   = $embeddedMetadata->identifier && isset($embeddedData[$embeddedMetadata->identifier]) ? $embeddedData[$embeddedMetadata->identifier] : null;

                // TODO: extract; this shouldn't really be responsibility of the hydrator
                if (empty($hints[Query::HINT_READ_ONLY])) {
                    $this->documentManager->getUnitOfWork()->registerManaged($embeddedDocument, $embeddedId, $embeddedData);
                }

                return $embeddedDocument;

            case ClassMetadata::REFERENCE_ONE:
                // TODO: inverse side
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

                // Todo: don't rely on document manager to provide factory
                return $this->documentManager->getReference($className, $id);

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
}
