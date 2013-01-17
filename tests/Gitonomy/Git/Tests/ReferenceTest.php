<?php

/**
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the GPL license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gitonomy\Git\Tests;

use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Reference\Tag;

class ReferenceTest extends AbstractTest
{
    private $references;

    public function setUp()
    {
        $this->references = $this->getLibRepository()->getReferences();
    }

    public function testGetBranch()
    {
        $branch = $this->references->getBranch('master');

        $this->assertTrue($branch instanceof Branch, "Branch object is correct type");
        $this->assertEquals($branch->getCommitHash(), $branch->getCommit()->getHash(), "Hash is correctly resolved");
    }

    public function testHasBranch()
    {
        $this->assertTrue($this->references->hasBranch('master'), 'Branch master exists');
        $this->assertFalse($this->references->hasBranch('foobar'), 'Branch foobar does not exists');
    }

    public function testHasTag()
    {
        $this->assertTrue($this->references->hasTag('0.1'), 'Tag master exists');
        $this->assertFalse($this->references->hasTag('foobar'), 'Tag foobar does not exists');
    }

    /**
     * @expectedException Gitonomy\Git\Exception\ReferenceNotFoundException
     */
    public function testGetBranch_NotExisting_Error()
    {
        $branch = $this->references->getBranch('notexisting');
    }

    public function testGetTag()
    {
        $tag = $this->references->getTag('0.1');

        $this->assertTrue($tag instanceof Tag, "Tag object is correct type");

        $this->assertEquals(self::INITIAL_COMMIT, $tag->getCommitHash(), "Commit hash is correct");
        $this->assertEquals(self::INITIAL_COMMIT, $tag->getCommit()->getHash(), "Commit hash is correct");
    }

    /**
     * @expectedException Gitonomy\Git\Exception\ReferenceNotFoundException
     */
    public function testGetTag_NotExisting_Error()
    {
        $branch = $this->references->getTag('notexisting');
    }

    public function testResolve()
    {
        $resolved = $this->references->resolve(self::INITIAL_COMMIT);

        $this->assertEquals(1, count($resolved), "1 revision resolved");
        $this->assertTrue($resolved[0] instanceof Tag, "Resolved object is a tag");
    }

    public function testResolveTags()
    {
        $resolved = $this->references->resolveTags(self::INITIAL_COMMIT);

        $this->assertEquals(1, count($resolved), "1 revision resolved");
        $this->assertTrue($resolved[0] instanceof Tag, "Resolved object is a tag");
    }

    public function testResolveBranches()
    {
        $master = $this->references->getBranch('master');

        $resolved = $this->references->resolveBranches($master->getCommitHash());

        $this->assertEquals(1, count($resolved), "1 revision resolved");
        $this->assertTrue($resolved[0] instanceof Branch, "Resolved object is a branch");
    }

    public function testCountable()
    {
        $this->assertGreaterThanOrEqual(2, count($this->references), "At least two references in repository");
    }

    public function testIterable()
    {
        $i = 0;
        foreach ($this->references as $ref) {
            $i++;
        }
        $this->assertGreaterThanOrEqual(2, $i, "At least two references in repository");
    }

    public function testCreateAndDeleteTag()
    {
        $references = $this->getLibRepository()->getReferences();
        $tag        = $references->createTag('0.0', self::INITIAL_COMMIT);

        $this->assertTrue($references->hasTag('0.0'), "Tag 0.0 created");
        $this->assertEquals(self::INITIAL_COMMIT, $tag->getCommit()->getHash());
        $this->assertSame($tag, $references->getTag('0.0'));

        $tag->delete();
        $this->assertFalse($references->hasTag('0.0'), "Tag 0.0 removed");
    }

    public function testCreateAndDeleteBranch()
    {
        $references = $this->getLibRepository()->getReferences();
        $branch     = $references->createBranch('foobar', self::INITIAL_COMMIT);

        $this->assertTrue($references->hasBranch('foobar'), "Branch foobar created");
        $this->assertEquals(self::INITIAL_COMMIT, $branch->getCommit()->getHash());
        $this->assertSame($branch, $references->getBranch('foobar'));

        $branch->delete();
        $this->assertFalse($references->hasBranch('foobar'), "Branch foobar removed");
    }
}
