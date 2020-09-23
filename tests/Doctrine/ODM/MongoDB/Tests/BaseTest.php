<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Tests;

use Doctrine\ODM\MongoDB\Configuration;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\Driver\AnnotationDriver;
use Doctrine\ODM\MongoDB\Tests\Query\Filter\Filter;
use Doctrine\ODM\MongoDB\UnitOfWork;
use MongoDB\Client;
use MongoDB\Driver\Query;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\Server;
use MongoDB\Model\DatabaseInfo;
use PHPUnit\Framework\TestCase;
use function current;
use const DOCTRINE_MONGODB_DATABASE;
use const DOCTRINE_MONGODB_SERVER;
use function array_key_exists;
use function array_map;
use function getenv;
use function in_array;
use function iterator_to_array;
use function preg_match;
use function version_compare;

abstract class BaseTest extends TestCase
{
    /** @var DocumentManager */
    protected $dm;
    /** @var UnitOfWork */
    protected $uow;

    public function setUp() : void
    {
        $this->dm  = $this->createTestDocumentManager();
        $this->uow = $this->dm->getUnitOfWork();
    }

    public function tearDown() : void
    {
        if (! $this->dm) {
            return;
        }

        // Check if the database exists. Calling listCollections on a non-existing
        // database in a sharded setup will cause an invalid command cursor to be
        // returned
        $client        = $this->dm->getClient();
        $databases     = iterator_to_array($client->listDatabases());
        $databaseNames = array_map(static function (DatabaseInfo $database) {
            return $database->getName();
        }, $databases);
        if (! in_array(DOCTRINE_MONGODB_DATABASE, $databaseNames)) {
            return;
        }

        $collections = $client->selectDatabase(DOCTRINE_MONGODB_DATABASE)->listCollections();

        foreach ($collections as $collection) {
            // See https://jira.mongodb.org/browse/SERVER-16541
            if (preg_match('#^system\.#', $collection->getName())) {
                continue;
            }

            $client->selectCollection(DOCTRINE_MONGODB_DATABASE, $collection->getName())->drop();
        }
    }

    protected function getConfiguration()
    {
        $config = new Configuration();

        $config->setProxyDir(__DIR__ . '/../../../../Proxies');
        $config->setProxyNamespace('Proxies');
        $config->setHydratorDir(__DIR__ . '/../../../../Hydrators');
        $config->setHydratorNamespace('Hydrators');
        $config->setPersistentCollectionDir(__DIR__ . '/../../../../PersistentCollections');
        $config->setPersistentCollectionNamespace('PersistentCollections');
        $config->setDefaultDB(DOCTRINE_MONGODB_DATABASE);
        $config->setMetadataDriverImpl($this->createMetadataDriverImpl());

        $config->addFilter('testFilter', Filter::class);
        $config->addFilter('testFilter2', Filter::class);

        return $config;
    }

    protected function createMetadataDriverImpl()
    {
        return AnnotationDriver::create(__DIR__ . '/../../../../Documents');
    }

    protected function createTestDocumentManager()
    {
        $config = $this->getConfiguration();
        $client = new Client(getenv('DOCTRINE_MONGODB_SERVER') ?: DOCTRINE_MONGODB_SERVER, [], ['typeMap' => ['root' => 'array', 'document' => 'array']]);

        return DocumentManager::create($client, $config);
    }

    protected function getServerVersion()
    {
        $result = $this->dm->getClient()->selectDatabase(DOCTRINE_MONGODB_DATABASE)->command(['buildInfo' => 1])->toArray()[0];

        return $result['version'];
    }

    private function getPrimaryServer()
    {
        return $this->dm->getClient()->getManager()->selectServer(new ReadPreference(ReadPreference::RP_PRIMARY));
    }

    protected function isReplicaSet()
    {
        return $this->getPrimaryServer()->getType() == Server::TYPE_RS_PRIMARY;
    }

    protected function isShardedCluster()
    {
        if ($this->getPrimaryServer()->getType() == Server::TYPE_MONGOS) {
            return true;
        }

        return false;
    }

    protected function isShardedClusterUsingReplicasets()
    {
        $cursor = $this->getPrimaryServer()->executeQuery(
            'config.shards',
            new Query([], ['limit' => 1])
        );

        $cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
        foreach ($cursor as $shard) {
            /**
             * Use regular expression to distinguish between standalone or replicaset:
             * Without a replicaset: "host" : "localhost:4100"
             * With a replicaset: "host" : "dec6d8a7-9bc1-4c0e-960c-615f860b956f/localhost:4400,localhost:4401"
             */
            if (!preg_match('@^.*/.*:\d+@', $shard['host'])) {
                return false;
            }
        }

        return true;
    }

    protected function skipIfTransactionsAreNotSupported()
    {
        if ($this->getPrimaryServer()->getType() === Server::TYPE_STANDALONE) {
            $this->markTestSkipped('Transactions are not supported on standalone servers');
        }

        if ($this->isShardedCluster()) {
            if (! $this->isShardedClusterUsingReplicasets()) {
                $this->markTestSkipped('Transactions are not supported on sharded clusters without replica sets');
            }

            $this->requireMongoDB42('Transactions are only supported on MongoDB 4.2 or higher');

            return;
        }

        $this->requireMongoDB40('Transactions are only supported on MongoDB 4.0 or higher');
    }

    protected function skipTestIfNotSharded($className)
    {
        $result = $this->dm->getDocumentDatabase($className)->command(['listCommands' => true])->toArray()[0];
        if (! $result['ok']) {
            $this->markTestSkipped('Could not check whether server supports sharding');
        }

        if (array_key_exists('shardCollection', $result['commands'])) {
            return;
        }

        $this->markTestSkipped('Test skipped because server does not support sharding');
    }

    protected function requireVersion($installedVersion, $requiredVersion, $operator, $message)
    {
        if (! version_compare($installedVersion, $requiredVersion, $operator)) {
            return;
        }

        $this->markTestSkipped($message);
    }

    protected function skipOnMongoDB42($message)
    {
        $this->requireVersion($this->getServerVersion(), '4.2.0', '>=', $message);
    }

    protected function requireMongoDB40($message)
    {
        $this->requireVersion($this->getServerVersion(), '4.0.0', '<', $message);
    }

    protected function requireMongoDB42($message)
    {
        $this->requireVersion($this->getServerVersion(), '4.2.0', '<', $message);
    }
}
