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

use PHPUnit\Framework\Attributes\DataProvider;

class BlameTest extends AbstractTestCase
{
    #[DataProvider('provideBare')]
    public function testBlame(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $blame = $repository->getBlame(self::LONGFILE_COMMIT, 'README.md');

        $this->assertCount(7, $blame);

        $this->assertSame('Foo Bar project', $blame->getLine(1)->getContent());

        $this->assertEquals('alice', $blame->getLine(1)->getCommit()->getAuthorName());
        $this->assertEquals(self::INITIAL_COMMIT, $blame->getLine(1)->getCommit()->getHash());

        $this->assertEquals('alice', $blame->getLine(5)->getCommit()->getAuthorName());
        $this->assertNotEquals(self::INITIAL_COMMIT, $blame->getLine(5)->getCommit()->getHash());
    }

    #[DataProvider('provideBare')]
    public function testGroupedBlame(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $blame = $repository->getBlame(self::LONGFILE_COMMIT, 'README.md')->getGroupedLines();

        $this->assertCount(3, $blame);

        $this->assertEquals(self::INITIAL_COMMIT, $blame[0][0]->getHash());
    }

    #[DataProvider('provideBare')]
    public function testBlameLineRange(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $full = $repository->getBlame(self::LONGFILE_COMMIT, 'README.md');

        foreach (['3,+3', '3,5'] as $lineRange) {
            $blame = $repository->getBlame(self::LONGFILE_COMMIT, 'README.md', $lineRange);

            $this->assertCount(3, $blame, $lineRange);
            foreach ([1 => 3, 2 => 4, 3 => 5] as $index => $lineNumber) {
                $this->assertSame((string) $lineNumber, $blame->getLine($index)->getLine(), $lineRange);
                $this->assertSame($full->getLine($lineNumber)->getCommit()->getHash(), $blame->getLine($index)->getCommit()->getHash(), $lineRange);
                $this->assertSame($full->getLine($lineNumber)->getContent(), $blame->getLine($index)->getContent(), $lineRange);
            }
        }
    }
}
