<?php

namespace Doctrine\ODM\MongoDB\Tests\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
use Doctrine\ODM\MongoDB\Tests\BaseTest;

class GH1597Test extends BaseTest
{
    public function testFailurePartA()
    {
        $a = new GH1597A;
        $b = new GH1597B;
        $c = new GH1597C($a);
        $b->cs->add($c);

        $this->dm->persist($a);
        $this->dm->persist($b);
        $this->dm->flush();
        $this->dm->clear();

        $a = $this->dm->find(GH1597A::class, $a->id);
        $this->assertInstanceOf(GH1597A::class, $a);

        return $a;
    }

    /**
     * @depends testFailurePartA
     */
    public function testFailurePartB($a)
    {
        $this->dm->merge($a);
    }
}

/**
 * @ODM\MappedSuperclass
 */
abstract class GH1597Base {
    /** @ODM\ReferenceMany(targetDocument=GH1597B::class, mappedBy="cs.d", cascade="all") */
    public $bs;

    public function __construct()
    {
        $this->bs = new ArrayCollection();
    }
}

/** @ODM\Document */
class GH1597A extends GH1597Base {
    /** @ODM\Id */
    public $id;
}

/** @ODM\Document */
class GH1597B {
    /** @ODM\Id */
    public $id;

    /** @ODM\EmbedMany(targetDocument=GH1597C::class) */
    public $cs;

    public function __construct()
    {
        $this->cs = new ArrayCollection();
    }
}

/** @ODM\EmbeddedDocument */
class GH1597C {
    /** @ODM\ReferenceOne(
     *     inversedBy="bs",
     *     targetDocument=GH1597Base::class,
     *     discriminatorField="t",
     *     discriminatorMap={"a"=GH1597A::class}
     * ) */
    public $a;

    public function __construct($a)
    {
        $this->a = $a;
    }
}
