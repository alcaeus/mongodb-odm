<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Benchmark\Document;

use Doctrine\ODM\MongoDB\Benchmark\BaseBench;
use Doctrine\ODM\MongoDB\Hydrator\BSONHydrator;
use Doctrine\ODM\MongoDB\Hydrator\HydratorInterface;
use Doctrine\ODM\MongoDB\PersistentCollection\DefaultPersistentCollectionFactory;
use Documents\User;
use Generator;
use MongoDB\BSON\Document;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Warmup;

use function ucfirst;

#[BeforeMethods(['initDocumentManager', 'clearDatabase', 'init'])]
#[Warmup(1)]
#[ParamProviders('getHydrators')]
final class HydrateDocumentBench extends BaseBench
{
    private static array $arrayData;
    private static array $arrayDataWithEmbedOne;
    private static array $arrayDataWithEmbedMany;
    private static array $arrayDataWithReferenceOne;
    private static array $arrayDataWithReferenceMany;

    private static Document $bsonData;
    private static Document $bsonDataWithEmbedOne;
    private static Document $bsonDataWithEmbedMany;
    private static Document $bsonDataWithReferenceOne;
    private static Document $bsonDataWithReferenceMany;

    private static HydratorInterface $arrayHydrator;
    private static HydratorInterface $bsonHydrator;

    public function init(): void
    {
        $data = [
            '_id' => new ObjectId(),
            'username' => 'alcaeus',
            'createdAt' => new UTCDateTime(),
        ];

        $embedOneData = [
            'address' => ['city' => 'Munich'],
        ];

        $embedManyData = [
            'phonenumbers' => [
                ['phonenumber' => '12345678'],
                ['phonenumber' => '12345678'],
            ],
        ];

        $referenceOneData = [
            'account' => [
                '$ref' => 'Account',
                '$id' => new ObjectId(),
            ],
        ];

        $referenceManyData = [
            'groups' => [
                [
                    '$ref' => 'Group',
                    '$id' => new ObjectId(),
                ],
                [
                    '$ref' => 'Group',
                    '$id' => new ObjectId(),
                ],
            ],
        ];

        self::$arrayData                  = $data;
        self::$arrayDataWithEmbedOne      = $data + $embedOneData;
        self::$arrayDataWithEmbedMany     = $data + $embedManyData;
        self::$arrayDataWithReferenceOne  = $data + $referenceOneData;
        self::$arrayDataWithReferenceMany = $data + $referenceManyData;

        self::$bsonData                  = Document::fromPHP(self::$arrayData);
        self::$bsonDataWithEmbedOne      = Document::fromPHP(self::$arrayDataWithEmbedOne);
        self::$bsonDataWithEmbedMany     = Document::fromPHP(self::$arrayDataWithEmbedMany);
        self::$bsonDataWithReferenceOne  = Document::fromPHP(self::$arrayDataWithReferenceOne);
        self::$bsonDataWithReferenceMany = Document::fromPHP(self::$arrayDataWithReferenceMany);

        $this->createArrayHydrator();
        $this->createBSONHydrator();
    }

    public function getHydrators(): Generator
    {
        yield 'ArrayHydrator' => ['type' => 'array'];
        yield 'BSONHydrator' => ['type' => 'bson'];
    }

    public function benchHydrateDocument(array $params): void
    {
        $type = $params['type'];
        $this->getHydrator($type)->hydrate(new User(), $this->getData($type, 'data'));
    }

    public function benchHydrateDocumentWithEmbedOne(array $params): void
    {
        $type = $params['type'];
        $this->getHydrator($type)->hydrate(new User(), $this->getData($type, 'dataWithEmbedOne'));
    }

    public function benchHydrateDocumentWithEmbedMany(array $params): void
    {
        $type = $params['type'];
        $this->getHydrator($type)->hydrate(new User(), $this->getData($type, 'dataWithEmbedMany'));
    }

    public function benchHydrateDocumentWithReferenceOne(array $params): void
    {
        $type = $params['type'];
        $this->getHydrator($type)->hydrate(new User(), $this->getData($type, 'dataWithReferenceOne'));
    }

    public function benchHydrateDocumentWithReferenceMany(array $params): void
    {
        $type = $params['type'];
        $this->getHydrator($type)->hydrate(new User(), $this->getData($type, 'dataWithReferenceMany'));
    }

    private function getData(string $type, string $dataset): mixed
    {
        $name = $type . ucfirst($dataset);

        return self::$$name ?? null;
    }

    private function getHydrator(string $type): HydratorInterface
    {
        $name = $type . 'Hydrator';

        return self::$$name;
    }

    private function createArrayHydrator(): void
    {
        self::$arrayHydrator = self::getDocumentManager()
            ->getHydratorFactory()
            ->getHydratorFor(User::class);
    }

    private function createBSONHydrator(): void
    {
        $dm = self::getDocumentManager();

        self::$bsonHydrator = new BSONHydrator(
            $dm,
            $dm->getClassMetadata(User::class),
            new DefaultPersistentCollectionFactory(),
        );
    }
}
