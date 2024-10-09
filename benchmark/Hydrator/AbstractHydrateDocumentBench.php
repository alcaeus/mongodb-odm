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

    /** @var array<string, HydratorInterface> */
    protected static array $hydrators;

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

        static::$dataset = static::prepareDataset([
            'userData' => Document::fromPHP($data),
            'userEmbedOne' => Document::fromPHP($data + $embedOneData),
            'userEmbedMany' => Document::fromPHP($data + $embedManyData),
            'userReferenceOne' => Document::fromPHP($data + $referenceOneData),
            'userReferenceMany' => Document::fromPHP($data + $referenceManyData),
        ]);
    }

    public function benchHydrateDocument(): void
    {
        $this->getHydrator(User::class)->hydrate(new User(), $this->getData('userData'));
    }

    public function benchHydrateDocumentWithEmbedOne(): void
    {
        $this->getHydrator(User::class)->hydrate(new User(), $this->getData('userEmbedOne'));
    }

    public function benchHydrateDocumentWithEmbedMany(): void
    {
        $this->getHydrator(User::class)->hydrate(new User(), $this->getData('userEmbedMany'));
    }

    public function benchHydrateDocumentWithReferenceOne(): void
    {
        $this->getHydrator(User::class)->hydrate(new User(), $this->getData('userReferenceOne'));
    }

    public function benchHydrateDocumentWithReferenceMany(): void
    {
        $this->getHydrator(User::class)->hydrate(new User(), $this->getData('userReferenceMany'));
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

    private function getHydrator(string $className): HydratorInterface
    {
        return static::$hydrators[$className] ??= static::getDocumentManager()
            ->getHydratorFactory()
            ->getHydratorFor($className);
    }
}
