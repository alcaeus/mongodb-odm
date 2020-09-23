<?php

namespace Doctrine\ODM\MongoDB\Tests\Functional;

use Doctrine\ODM\MongoDB\Tests\BaseTest;
use Documents\User;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;

class TransactionalFlushTest extends BaseTest
{
    public function setUp(): void
    {
        parent::setUp();

        $this->skipIfTransactionsAreNotSupported();
    }

    public function testFlushWithTransaction()
    {
        $session = $this->dm->getClient()->startSession();
        $session->startTransaction();

        $userId = new ObjectId();

        $user = new User();
        $user->setId($userId);

        $this->dm->persist($user);
        $this->dm->flush(['session' => $session]);

        $this->assertSame(0, $this->dm->getDocumentCollection(User::class)->countDocuments());
        $this->assertSame(1, $this->dm->getDocumentCollection(User::class)->countDocuments([], ['session' => $session]));

        $session->commitTransaction();

        $this->assertSame(1, $this->dm->getDocumentCollection(User::class)->countDocuments([]));
    }

    public function testFlushWithSubsequentCommitTransaction()
    {
        $userId = new ObjectId();

        $user = new User();
        $user->setId($userId);

        $this->dm->persist($user);
        $this->dm->flush();

        $session1 = $this->dm->getClient()->startSession();
        $session1->startTransaction();

        $session2 = $this->dm->getClient()->startSession();
        $session2->startTransaction();

        $user->setHits(2);

        $userCollection = $this->dm->getDocumentCollection(User::class);
        $userCollection->updateOne(['_id' => $userId], ['$set' => ['hits' => 1]], ['session' => $session1]);

        $session1->commitTransaction();
        $this->assertSame(1, $userCollection->countDocuments(['hits' => 1]));

        $this->dm->flush(['session' => $session2]);
        $session2->commitTransaction();

        $this->assertSame(1, $userCollection->countDocuments(['hits' => 2]));
    }

    public function testFlushWithParallelTransaction()
    {
        $user1Id = new ObjectId();
        $user2Id = new ObjectId();

        $user1 = new User();
        $user1->setId($user1Id);

        $user2 = new User();
        $user2->setId($user2Id);

        $this->dm->persist($user1);
        $this->dm->persist($user2);
        $this->dm->flush();

        $session1 = $this->dm->getClient()->startSession();
        $session1->startTransaction();

        $session2 = $this->dm->getClient()->startSession();
        $session2->startTransaction();

        $user1->setHits(2);
        $user2->setHits(2);

        $userCollection = $this->dm->getDocumentCollection(User::class);
        $userCollection->updateMany(['_id' => $user2Id], ['$set' => ['hits' => 1]], ['session' => $session1]);

        try {
            // Will raise a write conflict due to the document being modified in two transactions.
            // UnitOfWork ends up in a broken state which will cause subsequent failures.
            $this->dm->flush(['session' => $session2]);
            $this->fail('Expected a write conflict');
        } catch (BulkWriteException $e) {
            $this->assertTrue($e->hasErrorLabel('TransientTransactionError'));
        }

        $session1->commitTransaction();

        // The first transaction has completed, meaning that
        $this->assertSame(1, $userCollection->countDocuments(['hits' => 0]));
        $this->assertSame(1, $userCollection->countDocuments(['hits' => 1]));

        $session2->abortTransaction();

        $session2->startTransaction();
        $this->dm->flush();
        $session2->commitTransaction();

        // This assertion will fail: since the first update succeeded (even within a transaction),
        // the UnitOfWork will discard its update workload and lose it due to the subsequent
        // rollback.
        // This shows that UnitOfWork will need extensive modifications to correctly handle
        // transient transaction errors (e.g. two transactions trying to modify the same document).
        $this->assertSame(2, $userCollection->countDocuments(['hits' => 2]));
    }
}
