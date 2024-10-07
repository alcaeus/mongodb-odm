<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Benchmark\Hydrator;

use Doctrine\ODM\MongoDB\Benchmark\BaseBench;
use Doctrine\ODM\MongoDB\Configuration;
use Doctrine\ODM\MongoDB\Hydrator\HydratorInterface;
use Documents\User;
use InvalidArgumentException;
use MongoDB\BSON\Document;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

use function sprintf;

#[BeforeMethods(['initDocumentManager', 'init'])]
#[Warmup(1)]
#[Iterations(3)]
#[Revs(5)]
abstract class AbstractHydrateDocumentBench extends BaseBench
{
    protected static array $dataset;

    protected static Document $data;
    protected static Document $dataWithEmbedOne;
    protected static Document $dataWithEmbedMany;
    protected static Document $dataWithReferenceOne;
    protected static Document $dataWithReferenceMany;

    protected static HydratorInterface $hydrator;

    abstract protected function useBSONHydrator(): bool;

    abstract protected static function prepareDataset(array $dataset): array;

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

        static::$data                  = Document::fromPHP($data);
        static::$dataWithEmbedOne      = Document::fromPHP($data + $embedOneData);
        static::$dataWithEmbedMany     = Document::fromPHP($data + $embedManyData);
        static::$dataWithReferenceOne  = Document::fromPHP($data + $referenceOneData);
        static::$dataWithReferenceMany = Document::fromPHP($data + $referenceManyData);

        static::$dataset = static::prepareDataset([
            'data' => static::$data,
            'embedOne' => static::$dataWithEmbedOne,
            'embedMany' => static::$dataWithEmbedMany,
            'referenceOne' => static::$dataWithReferenceOne,
            'referenceMany' => static::$dataWithReferenceMany,
        ]);
    }

    public function benchHydrateDocument(): void
    {
        $this->getHydrator()->hydrate(new User(), $this->getData('data'));
    }

    public function benchHydrateDocumentWithEmbedOne(): void
    {
        $this->getHydrator()->hydrate(new User(), $this->getData('embedOne'));
    }

    public function benchHydrateDocumentWithEmbedMany(): void
    {
        $this->getHydrator()->hydrate(new User(), $this->getData('embedMany'));
    }

    public function benchHydrateDocumentWithReferenceOne(): void
    {
        $this->getHydrator()->hydrate(new User(), $this->getData('referenceOne'));
    }

    public function benchHydrateDocumentWithReferenceMany(): void
    {
        $this->getHydrator()->hydrate(new User(), $this->getData('referenceMany'));
    }

    protected function createDocumentManagerConfiguration(): Configuration
    {
        $config                  = parent::createDocumentManagerConfiguration();
        $config->useBSONHydrator = $this->useBSONHydrator();

        return $config;
    }

    private function getData(string $dataset): mixed
    {
        return static::$dataset[$dataset] ?? throw new InvalidArgumentException(sprintf('Invalid dataset "%s" requested', $dataset));
    }

    private function getHydrator(): HydratorInterface
    {
        return static::$hydrator ??= static::getDocumentManager()
            ->getHydratorFactory()
            ->getHydratorFor(User::class);
    }
}
