<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Tests\Functional;

use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Aggregation\Aggregation;
use Doctrine\ODM\MongoDB\Iterator\CachingIterator;
use Doctrine\ODM\MongoDB\Iterator\Iterator;
use Doctrine\ODM\MongoDB\Iterator\UnrewindableIterator;
use Doctrine\ODM\MongoDB\Tests\BaseTestCase;
use Documents\Article;
use Documents\BlogPost;
use Documents\BlogTagAggregation;
use Documents\CmsComment;
use Documents\GuestServer;
use Documents\Tag;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Builder\Accumulator;
use MongoDB\Builder\Expression;
use MongoDB\Builder\Pipeline;
use MongoDB\Builder\Query;
use MongoDB\Builder\Stage;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\TestWith;

use ReflectionProperty;
use function array_keys;
use function MongoDB\object;

class AggregationTest extends BaseTestCase
{
    public function testGetPipeline(): void
    {
        $point = ['type' => 'Point', 'coordinates' => [0, 0]];

        $expectedPipeline = [
            (object) [
                '$geoNear' => (object) [
                    'near' => $point,
                    'distanceField' => 'distance',
                    'query' => (object) [
                        'hasCoordinates' => (object) ['$exists' => true],
                        'username' => 'foo',
                    ],
                ],
            ],
            (object) ['$limit' => 10],
            (object) [
                '$match' => (object) [
                    '$or' => [
                        (object) ['username' => 'admin'],
                        (object) ['username' => 'administrator'],
                    ],
                    'group' => (object) ['$in' => ['a', 'b']],
                ],
            ],
            (object) ['$sample' => (object) ['size' => 10]],
            (object) [
                '$lookup' => (object) [
                    'from' => 'orders',
                    'localField' => '_id',
                    'foreignField' => 'user.$id',
                    'as' => 'orders',
                ],
            ],
            (object) ['$unwind' => (object) ['path' => 'a']],
            (object) ['$unwind' => (object) ['path' => 'b']],
            (object) [
                '$redact' => (object) [
                    '$cond' => (object) [
                        'if' => (object) ['$lte' => ['$accessLevel', 3]],
                        'then' => '$$KEEP',
                        'else' => '$$REDACT',
                    ],
                ],
            ],
            (object) [
                '$project' => (object) [
                    '_id' => false,
                    'user' => true,
                    'amount' => true,
                    'invoiceAddress' => true,
                    'deliveryAddress' => (object) [
                        '$cond' => (object) [
                            'if' => (object) [
                                '$and' => [
                                    (object) ['$eq' => ['$useAlternateDeliveryAddress', true]],
                                    (object) ['$ne' => ['$deliveryAddress', null]],
                                ],
                            ],
                            'then' => '$deliveryAddress',
                            'else' => '$invoiceAddress',
                        ],
                    ],
                ],
            ],
            (object) [
                '$group' => (object) [
                    '_id' => '$user',
                    'numOrders' => (object) ['$count' => (object) []],
                    'amount' => (object) [
                        'total' => (object) ['$sum' => '$amount'],
                        'avg' => (object) ['$avg' => '$amount'],
                    ],
                ],
            ],
            (object) ['$sort' => (object) ['totalAmount' => 1]],
            (object) ['$sort' => (object) ['numOrders' => -1, 'avgAmount' => 1]],
            (object) ['$limit' => 5],
            (object) ['$skip' => 2],
            ['$foo' => 'bar'],
            (object) ['$out' => 'collectionName'],
        ];

        $pipeline = new Pipeline(
            Stage::geoNear(
                near: $point,
                distanceField: 'distance',
                query: Query::query(hasCoordinates: Query::exists(true), username: 'foo'),
            ),
            Stage::limit(10),
            Stage::match(
                Query::query(group: Query::in(['a', 'b'])),
                Query::or(
                    Query::query(username: 'admin'),
                    Query::query(username: 'administrator'),
                ),
            ),
            Stage::sample(10),
            Stage::lookup(
                as: 'orders',
                from: 'orders',
                localField: '_id',
                foreignField: 'user.$id',
            ),
            Stage::unwind('a'),
            Stage::unwind('b'),
            Stage::redact(
                Expression::cond(
                    if: Expression::lte(Expression::fieldPath('accessLevel'), 3),
                    then: Expression::variable('KEEP'),
                    else: Expression::variable('REDACT'),
                ),
            ),
            Stage::project(
                _id: false,
                user: true,
                amount: true,
                invoiceAddress: true,
                deliveryAddress: Expression::cond(
                    if: Expression::and(
                        Expression::eq(Expression::fieldPath('useAlternateDeliveryAddress'), true),
                        Expression::ne(Expression::fieldPath('deliveryAddress'), null),
                    ),
                    then: Expression::fieldPath('deliveryAddress'),
                    else: Expression::fieldPath('invoiceAddress'),
                ),
            ),
            Stage::group(
                _id: Expression::fieldPath('user'),
                numOrders: Accumulator::count(),
                amount: object(
                    total: Accumulator::sum(Expression::intFieldPath('amount')),
                    avg: Accumulator::avg(Expression::intFieldPath('amount')),
                ),
            ),
            Stage::sort(totalAmount: 1),
            Stage::sort(numOrders: -1, avgAmount: 1),
            Stage::limit(5),
            Stage::skip(2),
            ['$foo' => 'bar'],
            Stage::out('collectionName'),
        );

        $aggregation = $this->dm->getRepository(BlogPost::class)->aggregate($pipeline);

        self::assertEquals($expectedPipeline, $this->getPipeline($aggregation));
    }

    public function testAggregationBuilder(): void
    {
        $this->insertTestData();

        $pipeline = new Pipeline(
            Stage::unwind(Expression::arrayFieldPath('tags')),
            Stage::group(
                _id: Expression::fieldPath('tags'),
                numPosts: Accumulator::count(),
            ),
            Stage::sort(numPosts: -1),
        );

        $resultCursor = $this->dm
            ->getRepository(BlogPost::class)
            ->aggregate($pipeline, hydrationClass: BlogTagAggregation::class)
            ->execute();

        self::assertInstanceOf(Iterator::class, $resultCursor);

        $results = $resultCursor->toArray();
        self::assertCount(2, $results);
        self::assertInstanceOf(BlogTagAggregation::class, $results[0]);

        self::assertSame('baseball', $results[0]->tag->name);
        self::assertSame(3, $results[0]->numPosts);
    }

    public function testAggregationBuilderWithoutHydration(): void
    {
        $this->insertTestData();

        $pipeline = new Pipeline(
            Stage::unwind(Expression::arrayFieldPath('tags')),
            Stage::group(
                _id: Expression::fieldPath('tags'),
                numPosts: Accumulator::count(),
            ),
            Stage::sort(numPosts: -1),
        );

        $resultCursor = $this->dm
            ->getRepository(BlogPost::class)
            ->aggregate($pipeline)
            ->execute();

        self::assertInstanceOf(Iterator::class, $resultCursor);

        $results = $resultCursor->toArray();
        self::assertCount(2, $results);
        self::assertIsArray($results[0]);
        self::assertInstanceOf(ObjectId::class, $results[0]['_id']['$id']);
        self::assertSame('Tag', $results[0]['_id']['$ref']);
        self::assertSame(3, $results[0]['numPosts']);
    }

    public function testGetAggregation(): void
    {
        $this->insertTestData();

        $pipeline = new Pipeline(
            Stage::unwind(Expression::arrayFieldPath('tags')),
            Stage::group(
                _id: Expression::fieldPath('tags'),
                numPosts: Accumulator::count(),
            ),
            Stage::sort(numPosts: -1),
        );

        $aggregation = $this->dm
            ->getRepository(BlogPost::class)
            ->aggregate($pipeline, hydrationClass: BlogTagAggregation::class);

        self::assertInstanceOf(Aggregation::class, $aggregation);

        $resultCursor = $aggregation->getIterator();

        self::assertInstanceOf(Iterator::class, $resultCursor);

        $results = $resultCursor->toArray();
        self::assertCount(2, $results);
        self::assertInstanceOf(BlogTagAggregation::class, $results[0]);

        self::assertSame('baseball', $results[0]->tag->name);
        self::assertSame(3, $results[0]->numPosts);
    }

    public function testPipelineConvertsTypes(): void
    {
        $dateTime = new DateTimeImmutable('2000-01-01T00:00Z');
        $pipeline = new Pipeline(
            Stage::group(
                _id: Expression::cond(
                    if: Expression::lt(Expression::fieldPath('createdAt'), $dateTime),
                    then: true,
                    else: false,
                ),
                numPosts: Accumulator::count(),
            ),
            Stage::replaceRoot(
                object(
                    isToday: Expression::eq(Expression::fieldPath('createdAt'), $dateTime),
                ),
            ),
        );

        $expectedPipeline = [
            (object) [
                '$group' => (object) [
                    '_id' => (object) [
                        '$cond' => (object) [
                            'if' => (object) ['$lt' => ['$createdAt', new UTCDateTime($dateTime)]],
                            'then' => true,
                            'else' => false,
                        ],
                    ],
                    'numPosts' => (object) ['$count' => (object) []],
                ],
            ],
            (object) [
                '$replaceRoot' => (object) [
                    'newRoot' => (object) [
                        'isToday' => (object) [
                            '$eq' => ['$createdAt', new UTCDateTime($dateTime)],
                        ],
                    ],
                ],
            ],
        ];

        $aggregation = $this->dm->getRepository(Article::class)->aggregate($pipeline);

        self::assertEquals($expectedPipeline, $this->getPipeline($aggregation));
    }

    public function testFieldNameConversion(): void
    {
        $pipeline = new Pipeline(
            Stage::match(
                authorIp: Query::ne('127.0.0.1'),
            ),
            Stage::project(
                authorIp: true,
            ),
            Stage::unwind(Expression::arrayFieldPath('authorIp')),
            Stage::sort(authorIp: 1),
            Stage::replaceRoot(Expression::objectFieldPath('authorIp')),
        );

        $builder = $this->dm->createAggregationBuilder(CmsComment::class);
        $builder
            ->match()
                ->field('authorIp')
                ->notEqual('127.0.0.1')
            ->project()
                ->includeFields(['authorIp'])
            ->unwind('authorIp')
            ->sort('authorIp', 'asc')
            ->replaceRoot('$authorIp');

        $expectedPipeline = [
            (object) [
                '$match' => (object) ['ip' => (object) ['$ne' => '127.0.0.1']],
            ],
            (object) [
                '$project' => (object) ['ip' => true],
            ],
            (object) ['$unwind' => (object) ['path' => '$ip']],
            (object) [
                '$sort' => (object) ['ip' => 1],
            ],
            (object) ['$replaceRoot' => (object) ['newRoot' => '$ip']],
        ];

        $aggregation = $this->dm->getRepository(CmsComment::class)->aggregate($pipeline);

        self::assertEquals($expectedPipeline, $this->getPipeline($aggregation));
    }

    public function testBuilderAppliesFilterAndDiscriminatorWithMatchStage(): void
    {
        $this->markTestIncomplete('Needs rewriting');

        $this->dm->getFilterCollection()->enable('testFilter');
        $filter = $this->dm->getFilterCollection()->getFilter('testFilter');
        $filter->setParameter('class', GuestServer::class);
        $filter->setParameter('field', 'filtered');
        $filter->setParameter('value', true);

        $builder = $this->dm->createAggregationBuilder(GuestServer::class);
        $builder
            ->project()
            ->excludeFields(['_id']);

        $expectedPipeline = [
            [
                '$match' => [
                    '$and' => [
                        ['stype' => 'server_guest'],
                        ['filtered' => true],
                    ],
                ],

            ],
            [
                '$project' => ['_id' => false],
            ],
        ];

        self::assertEquals($expectedPipeline, $builder->getPipeline());
    }

    public function testBuilderMergeFilterAndDiscriminatorWithMatchStage(): void
    {
        $this->markTestIncomplete('Needs rewriting');

        $this->dm->getFilterCollection()->enable('testFilter');
        $filter = $this->dm->getFilterCollection()->getFilter('testFilter');
        $filter->setParameter('class', GuestServer::class);
        $filter->setParameter('field', 'filtered');
        $filter->setParameter('value', true);

        $builder = $this->dm->createAggregationBuilder(GuestServer::class);
        $builder
            ->match()
                ->text('Paul');

        $expectedPipeline = [
            [
                '$match' => [
                    '$and' => [
                        [
                            'stype' => 'server_guest',
                            '$text' => ['$search' => 'Paul'],
                        ],
                        ['filtered' => true],
                    ],
                ],
            ],
        ];

        self::assertEquals($expectedPipeline, $builder->getPipeline());
    }

    public function testBuilderAppliesFilterAndDiscriminatorWithGeoNearStage(): void
    {
        $this->markTestIncomplete('Needs rewriting');

        $this->dm->getFilterCollection()->enable('testFilter');
        $filter = $this->dm->getFilterCollection()->getFilter('testFilter');
        $filter->setParameter('class', GuestServer::class);
        $filter->setParameter('field', 'filtered');
        $filter->setParameter('value', true);

        $builder = $this->dm->createAggregationBuilder(GuestServer::class);
        $builder
            ->geoNear(0, 0);

        $expectedPipeline = [
            [
                '$geoNear' => [
                    'near' => [0, 0],
                    'spherical' => false,
                    'distanceField' => null,
                    'query' => [
                        '$and' => [
                            ['stype' => 'server_guest'],
                            ['filtered' => true],
                        ],
                    ],
                ],
            ],
        ];

        self::assertEquals($expectedPipeline, $builder->getPipeline());
    }

    public function testBuilderWithOutStageReturnsNoData(): void
    {
        $this->insertTestData();

        $pipeline = new Pipeline(
            Stage::out('sampleCollection'),
        );

        $result = $this->dm->getRepository(BlogPost::class)->aggregate($pipeline)->getIterator();
        self::assertEmpty($result);
    }

    public function testBuilderWithIndexStatsStageDoesNotApplyFilters(): void
    {
        $this->markTestIncomplete('Needs rewriting');

        $builder = $this->dm
            ->createAggregationBuilder(BlogPost::class)
            ->indexStats();

        self::assertSame('$indexStats', array_keys($builder->getPipeline()[0])[0]);
    }

    #[IgnoreDeprecations]
    #[TestWith([false, UnrewindableIterator::class])]
    #[TestWith([true, CachingIterator::class])]
    public function testExecute(bool $rewindable, string $iteratorClass): void
    {
        $this->markTestIncomplete('Needs rewriting');

        $builder = $this->dm
            ->createAggregationBuilder(BlogPost::class)
            ->match()
            ->rewindable($rewindable);

        $iterator = $builder->getAggregation()->execute();
        self::assertInstanceOf($iteratorClass, $iterator);
    }

    public function testNonRewindableBuilder(): void
    {
        $this->markTestIncomplete('Needs rewriting');

        $builder = $this->dm
            ->createAggregationBuilder(BlogPost::class)
            ->match()
            ->rewindable(false);

        $iterator = $builder->getAggregation()->execute();
        self::assertInstanceOf(UnrewindableIterator::class, $iterator);
    }

    private function insertTestData(): void
    {
        $baseballTag = new Tag('baseball');
        $footballTag = new Tag('football');

        $blogPost       = new BlogPost();
        $blogPost->name = 'Test 1';
        $blogPost->addTag($baseballTag);
        $this->dm->persist($blogPost);

        $blogPost       = new BlogPost();
        $blogPost->name = 'Test 2';
        $blogPost->addTag($baseballTag);
        $this->dm->persist($blogPost);

        $blogPost       = new BlogPost();
        $blogPost->name = 'Test 3';
        $blogPost->addTag($footballTag);
        $this->dm->persist($blogPost);

        $blogPost       = new BlogPost();
        $blogPost->name = 'Test 4';
        $blogPost->addTag($baseballTag);
        $blogPost->addTag($footballTag);
        $this->dm->persist($blogPost);

        $this->dm->flush();
        $this->dm->clear();
    }

    private function getPipeline(Aggregation $aggregation): mixed
    {
        $reflectionProperty = new ReflectionProperty($aggregation, 'pipeline');
        $pipeline = $reflectionProperty->getValue($aggregation);
        return $pipeline;
    }
}
