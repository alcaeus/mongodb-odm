<?php

namespace Doctrine\ODM\MongoDB\Tests\Functional\Ticket;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
use Doctrine\ODM\MongoDB\Query\Query;

class GH1418Test extends \Doctrine\ODM\MongoDB\Tests\BaseTest
{
    public function testManualHydrateAndMerge()
    {
        $document = new GH1418Document;
        $this->dm->getHydratorFactory()->hydrate($document, array(
          '_id' => 1,
          'name' => 'maciej',
          'embedOne' => ['name' => 'maciej', 'alsoLoadMe' => 1],
          'embedMany' => [
              ['name' => 'maciej', 'alsoLoadMe' => 1]
          ],
        ), [ Query::HINT_READ_ONLY => true ]);

        $this->dm->merge($document);
        $this->dm->flush();
        $this->dm->clear();

        $document = $this->dm->getRepository(GH1418Document::class)->find(1);
        $this->assertEquals(1, $document->id);
        $this->assertEquals('maciej', $document->embedOne->name);
        $this->assertEquals(1, $document->embedMany->count());
        $this->assertEquals('maciej', $document->embedMany->first()->name);
        $this->assertEquals(1, $document->embedOne->alsoLoadValue);
        $this->assertEquals(1, $document->embedMany->first()->alsoLoadValue);
    }

    public function testReadDocumentAndManage()
    {
        $document = new GH1418Document;
        $document->id = 1;

        $embedded = new GH1418Embedded();
        $embedded->name = 'maciej';

        $document->embedOne = clone $embedded;
        $document->embedMany[] = clone $embedded;

        $this->dm->persist($document);
        $this->dm->flush();
        $this->dm->clear();

        $document = $this->dm->createQueryBuilder(GH1418Document::class)
            ->readOnly(true)
            ->field('id')
            ->equals(1)
            ->getQuery()
            ->getSingleResult();

        $this->assertEquals(1, $document->id);
        $this->assertEquals('maciej', $document->embedOne->name);
        $this->assertEquals(1, $document->embedMany->count());
        $this->assertEquals('maciej', $document->embedMany->first()->name);

        $document = $this->dm->merge($document);

        $document->embedOne->name = 'alcaeus';
        $document->embedMany[0]->name = 'alcaeus';

        $this->dm->flush();
        $this->dm->clear();

        $document = $this->dm->getRepository(GH1418Document::class)->find(1);
        $this->assertEquals(1, $document->id);
        $this->assertEquals('alcaeus', $document->embedOne->name);
        $this->assertEquals(1, $document->embedMany->count());
        $this->assertEquals('alcaeus', $document->embedMany->first()->name);

        $document->embedMany[] = clone $embedded;

        $document = $this->dm->merge($document);
        $this->dm->flush();
        $this->dm->clear();

        $document = $this->dm->getRepository(GH1418Document::class)->find(1);
        $this->assertEquals(1, $document->id);
        $this->assertEquals('alcaeus', $document->embedOne->name);
        $this->assertEquals(2, $document->embedMany->count());
        $this->assertEquals('maciej', $document->embedMany->last()->name);
    }
}

/** @ODM\Document */
class GH1418Document
{
    /** @ODM\Id(strategy="none") */
    public $id;

    /** @ODM\EmbedOne(targetDocument="GH1418Embedded") */
    public $embedOne;

    /** @ODM\EmbedMany(targetDocument="GH1418Embedded") */
    public $embedMany;
}

/** @ODM\EmbeddedDocument */
class GH1418Embedded
{
    /** @ODM\Field(type="string") */
    public $name;

    /**
     * @ODM\Field(type="int")
     * @ODM\AlsoLoad("alsoLoadMe")
     * **/
    public $alsoLoadValue;
}
