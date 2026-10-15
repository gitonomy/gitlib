<?php

/**
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gitonomy\Git\Tests;

use Gitonomy\Git\Exception\LogicException;
use Gitonomy\Git\PushReference;
use Symfony\Bridge\PhpUnit\ExpectDeprecationTrait;

class PushReferenceTest extends AbstractTest
{
    use ExpectDeprecationTrait;

    const CREATE = 1;
    const DELETE = 2;
    const FORCE = 4;
    const FAST_FORWARD = 8;

    public function provideIsers()
    {
        // mask: force fastforward create delete
        return [
            ['foo', PushReference::ZERO,          self::LONGFILE_COMMIT,        self::CREATE],
            ['foo', self::LONGFILE_COMMIT,        PushReference::ZERO,          self::DELETE],
            ['foo', self::LONGFILE_COMMIT,        self::BEFORE_LONGFILE_COMMIT, self::FORCE],
            ['foo', self::BEFORE_LONGFILE_COMMIT, self::LONGFILE_COMMIT,        self::FAST_FORWARD],
        ];
    }

    /**
     * @dataProvider provideIsers
     */
    public function testIsers($reference, $before, $after, $mask)
    {
        $reference = new PushReference(self::createFoobarRepository(), $reference, $before, $after);
        $this->assertEquals($mask & self::CREATE, $reference->isCreation(), 'Create value is correct.');
        $this->assertEquals($mask & self::DELETE, $reference->isDeletion(), 'Delete value is correct.');
        $this->assertEquals($mask & self::FORCE, $reference->isForce(), 'Force value is correct.');
        $this->assertEquals($mask & self::FAST_FORWARD, $reference->isFastForward(), 'FastForward value is correct.');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testLog($repository)
    {
        $ref = new PushReference($repository, 'foo', self::INITIAL_COMMIT, self::LONGFILE_COMMIT);

        $log = $ref->getLog()->getCommits();
        $this->assertCount(7, $log, '7 commits in log');
        $this->assertEquals('add a long file', $log[0]->getSubjectMessage(), 'First commit is correct');
    }

    /**
     * This test ensures that GPG signed requests does not break the reading of commit logs.
     *
     * @dataProvider provideFoobar
     */
    public function testSignedLog($repository)
    {
        $ref = new PushReference($repository, 'foo', self::INITIAL_COMMIT, self::SIGNED_COMMIT);
        $log = $ref->getLog()->getCommits();
        $this->assertCount(16, $log, '16 commits in log');
        $this->assertEquals('signed commit', $log[0]->getSubjectMessage(), 'Last commit is correct');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testLogWithExclude($repository)
    {
        $ref = new PushReference($repository, 'foo', PushReference::ZERO, self::LONGFILE_COMMIT);

        $log = $ref->getLog([self::INITIAL_COMMIT])->getCommits();
        $this->assertCount(7, $log, '7 commits in log');
        $this->assertEquals('add a long file', $log[0]->getSubjectMessage(), 'First commit is correct');
    }

    public function testFullnameAndRange()
    {
        $repository = self::createFoobarRepository();

        $reference = new PushReference($repository, 'refs/heads/foo', self::BEFORE_LONGFILE_COMMIT, self::LONGFILE_COMMIT);
        $this->assertSame('refs/heads/foo', $reference->getFullname());
        $this->assertSame(self::BEFORE_LONGFILE_COMMIT.'..'.self::LONGFILE_COMMIT, $reference->getRange());

        $creation = new PushReference($repository, 'refs/heads/foo', PushReference::ZERO, self::LONGFILE_COMMIT);
        $this->assertSame(self::LONGFILE_COMMIT, $creation->getRange());
    }

    public function testRangeOfADeletion()
    {
        $reference = new PushReference(self::createFoobarRepository(), 'refs/heads/foo', self::LONGFILE_COMMIT, PushReference::ZERO);

        $this->expectException(LogicException::class);

        $reference->getRange();
    }

    /**
     * @group legacy
     */
    public function testDeprecatedNames()
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\PushReference::getReference()" method is deprecated, use "getFullname()" instead.');
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\PushReference::getRevision()" method is deprecated, use "getRange()" instead.');
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\PushReference::isCreate()" method is deprecated, use "isCreation()" instead.');
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\PushReference::isDelete()" method is deprecated, use "isDeletion()" instead.');

        $reference = new PushReference(self::createFoobarRepository(), 'refs/heads/foo', PushReference::ZERO, self::LONGFILE_COMMIT);

        $this->assertSame('refs/heads/foo', $reference->getReference());
        $this->assertSame(self::LONGFILE_COMMIT, $reference->getRevision());
        $this->assertTrue($reference->isCreate());
        $this->assertFalse($reference->isDelete());
    }
}
