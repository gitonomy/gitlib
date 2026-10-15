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

use Gitonomy\Git\Blame\Line;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Symfony\Bridge\PhpUnit\ExpectDeprecationTrait;

class BlameTest extends AbstractTest
{
    use ExpectDeprecationTrait;

    const README_COMMIT = 'cfba9c69819a96df0635e2e7ddc86da20f954db6';

    /**
     * @dataProvider provideFoobar
     */
    public function testBlame($repository)
    {
        $blame = $repository->getBlame(self::LONGFILE_COMMIT, 'README.md');

        $this->assertCount(7, $blame);

        $this->assertEquals('alice', $blame->getLine(1)->getCommit()->getAuthorName());
        $this->assertEquals(self::INITIAL_COMMIT, $blame->getLine(1)->getCommit()->getHash());

        $this->assertEquals('alice', $blame->getLine(5)->getCommit()->getAuthorName());
        $this->assertNotEquals(self::INITIAL_COMMIT, $blame->getLine(5)->getCommit()->getHash());
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testHunks($repository)
    {
        $hunks = $repository->getBlame(self::LONGFILE_COMMIT, 'README.md')->getHunks();

        $this->assertCount(3, $hunks);
        $this->assertSame(self::INITIAL_COMMIT, $hunks[0]->getCommit()->getHash());
        $this->assertSame([1, 2, 3], array_keys($hunks[0]->getLines()));
        $this->assertSame(self::README_COMMIT, $hunks[1]->getCommit()->getHash());
        $this->assertSame([4, 5, 6], array_keys($hunks[1]->getLines()));
        $this->assertSame([7], array_keys($hunks[2]->getLines()));

        $line = $hunks[2]->getLines()[7];
        $this->assertSame(7, $line->getNumber());
        // The line 5 of the file in the initial commit.
        $this->assertSame(5, $line->getOriginalNumber());
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testRangeOfLines($repository)
    {
        $blame = $repository->getBlame(self::LONGFILE_COMMIT, 'README.md', 4, 6);

        $this->assertCount(3, $blame);
        $this->assertSame([4, 5, 6], array_map(function (Line $line) {
            return $line->getNumber();
        }, array_values($blame->getLines())));

        $hunks = $blame->getHunks();
        $this->assertCount(1, $hunks);
        // Numbered like the file, unlike getLines(), which numbers them from 1.
        $this->assertSame([4, 5, 6], array_keys($hunks[0]->getLines()));
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testRangeOfLinesWithoutLastLine($repository)
    {
        $this->assertCount(3, $repository->getBlame(self::LONGFILE_COMMIT, 'README.md', 5));
        $this->assertCount(2, $repository->getBlame(self::LONGFILE_COMMIT, 'README.md', null, 2));
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testRangeOfLinesInReverse($repository)
    {
        $this->assertCount(3, $repository->getBlame(self::LONGFILE_COMMIT, 'README.md', 6, 4));
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testRangeOfLinesFromZero($repository)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid range of lines "0,3": the lines are numbered from 1.');

        $repository->getBlame(self::LONGFILE_COMMIT, 'README.md', 0, 3);
    }

    /**
     * @dataProvider provideFoobar
     *
     * @group legacy
     */
    public function testRangeOfLinesAsAString($repository)
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: Passing a range of lines as a string to "Gitonomy\Git\Repository::getBlame()" or "Gitonomy\Git\Blame::__construct()" is deprecated, pass the first and the last lines instead.');

        $this->assertCount(3, $repository->getBlame(self::LONGFILE_COMMIT, 'README.md', '4,+3'));
    }

    /**
     * @dataProvider provideFoobar
     *
     * @group legacy
     */
    public function testLineGetLine($repository)
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Blame\Line::getLine()" method is deprecated, use "getOriginalNumber()" instead.');

        $this->assertSame('5', $repository->getBlame(self::LONGFILE_COMMIT, 'README.md')->getLine(7)->getLine());
    }

    /**
     * @dataProvider provideFoobar
     *
     * @group legacy
     */
    public function testGroupedBlame($repository)
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Blame::getGroupedLines()" method is deprecated, use "getHunks()" instead.');

        $blame = $repository->getBlame(self::LONGFILE_COMMIT, 'README.md')->getGroupedLines();

        $this->assertCount(3, $blame);

        $this->assertEquals(self::INITIAL_COMMIT, $blame[0][0]->getHash());
    }
}
