<?php

/*
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gitonomy\Git\Tests;

use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\PushReference;
use PHPUnit\Framework\Attributes\DataProvider;

class PushReferenceTest extends AbstractTestCase
{
    public const CREATE = 1;
    public const DELETE = 2;
    public const FORCE = 4;
    public const FAST_FORWARD = 8;

    #[DataProvider('provideIsers')]
    public function testIsers(string $reference, string $before, string $after, int $mask): void
    {
        $reference = new PushReference(self::createFoobarRepository(), $reference, $before, $after);
        $this->assertSame(0 !== ($mask & self::CREATE), $reference->isCreate(), 'Create value is correct.');
        $this->assertSame(0 !== ($mask & self::DELETE), $reference->isDelete(), 'Delete value is correct.');
        $this->assertSame(0 !== ($mask & self::FORCE), $reference->isForce(), 'Force value is correct.');
        $this->assertSame(0 !== ($mask & self::FAST_FORWARD), $reference->isFastForward(), 'FastForward value is correct.');
    }

    /**
     * @return list<array{string, string, string, int}>
     */
    public static function provideIsers(): array
    {
        // mask: force fastforward create delete
        return [
            ['foo', PushReference::ZERO,          self::LONGFILE_COMMIT,        self::CREATE],
            ['foo', self::LONGFILE_COMMIT,        PushReference::ZERO,          self::DELETE],
            ['foo', self::LONGFILE_COMMIT,        self::BEFORE_LONGFILE_COMMIT, self::FORCE],
            ['foo', self::BEFORE_LONGFILE_COMMIT, self::LONGFILE_COMMIT,        self::FAST_FORWARD],
        ];
    }

    public function testUnknownBeforeIsAnErrorNotAForcePush(): void
    {
        $reference = new PushReference(self::createFoobarRepository(), 'foo', str_repeat('1', 40), self::LONGFILE_COMMIT);

        $this->expectException(DriverException::class);

        $reference->isForce();
    }

    #[DataProvider('provideBare')]
    public function testLog(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $ref = new PushReference($repository, 'foo', self::INITIAL_COMMIT, self::LONGFILE_COMMIT);

        $log = $ref->getLog()->getCommits();
        $this->assertCount(7, $log, '7 commits in log');
        $this->assertSame('add a long file', $log[0]->getSubjectMessage(), 'First commit is correct');
    }

    /**
     * This test ensures that GPG signed requests does not break the reading of commit logs.
     */
    #[DataProvider('provideBare')]
    public function testSignedLog(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $ref = new PushReference($repository, 'foo', self::INITIAL_COMMIT, self::SIGNED_COMMIT);
        $log = $ref->getLog()->getCommits();
        $this->assertCount(16, $log, '16 commits in log');
        $this->assertSame('signed commit', $log[0]->getSubjectMessage(), 'Last commit is correct');
    }

    #[DataProvider('provideBare')]
    public function testLogWithExclude(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $ref = new PushReference($repository, 'foo', PushReference::ZERO, self::LONGFILE_COMMIT);

        $log = $ref->getLog([self::INITIAL_COMMIT])->getCommits();
        $this->assertCount(7, $log, '7 commits in log');
        $this->assertSame('add a long file', $log[0]->getSubjectMessage(), 'First commit is correct');
    }
}
