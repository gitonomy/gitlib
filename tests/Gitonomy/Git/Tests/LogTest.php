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

use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Parser\LogParser;
use Symfony\Bridge\PhpUnit\ExpectDeprecationTrait;

class LogTest extends AbstractTest
{
    use ExpectDeprecationTrait;

    /**
     * @dataProvider provideFoobar
     */
    public function testWithOffsetAndLimit($repository)
    {
        $log = $repository->getLog(self::LONGFILE_COMMIT);

        $page = $log->withOffset(1)->withLimit(1);

        $this->assertSame([self::BEFORE_LONGFILE_COMMIT], array_map(function ($commit) {
            return $commit->getHash();
        }, $page->getCommits()));
        $this->assertSame(1, $page->getOffset());
        $this->assertSame(1, $page->getLimit());
        $this->assertNull($log->getOffset());
        $this->assertNull($log->getLimit());
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testWithNegativeOffset($repository)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The offset of a log can\'t be negative, -1 given.');

        $repository->getLog()->withOffset(-1);
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testWithNegativeLimit($repository)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The limit of a log can\'t be negative, -1 given.');

        $repository->getLog()->withLimit(-1);
    }

    /**
     * @dataProvider provideFoobar
     *
     * @group legacy
     */
    public function testSetOffsetAndLimit($repository)
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Log::setOffset()" method is deprecated, use "withOffset()" instead.');
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Log::setLimit()" method is deprecated, use "withLimit()" instead.');

        $log = $repository->getLog(self::LONGFILE_COMMIT);
        $log->setOffset(1)->setLimit(1);

        $this->assertSame(self::BEFORE_LONGFILE_COMMIT, $log->getSingleCommit()->getHash());
    }

    /**
     * @dataProvider provideFoobar
     *
     * @group legacy
     */
    public function testNegativeOffsetAndLimit($repository)
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: Passing a negative offset to "Gitonomy\Git\Log::__construct()" is deprecated, it throws in 2.0.');
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: Passing a negative limit to "Gitonomy\Git\Log::__construct()" is deprecated, it throws in 2.0: pass null for no limit.');

        $log = $repository->getLog(self::LONGFILE_COMMIT, null, -1, -1);

        $this->assertCount(8, $log->getCommits());
    }
    /**
     * @dataProvider provideFoobar
     */
    public function testRevisionAndPath($repository)
    {
        $logReadme = $repository->getLog(self::LONGFILE_COMMIT, 'README');
        $logImage = $repository->getLog(self::LONGFILE_COMMIT, 'image.jpg');

        $this->assertCount(3, $logReadme);
        $this->assertCount(2, $logImage);
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testGetCommits($repository)
    {
        $log = $repository->getLog(self::LONGFILE_COMMIT, null, null, 3);

        $commits = $log->getCommits();

        $this->assertCount(3, $commits, '3 commits in log');
        $this->assertEquals(self::LONGFILE_COMMIT, $commits[0]->getHash(), 'First is requested one');
        $this->assertEquals(self::BEFORE_LONGFILE_COMMIT, $commits[1]->getHash(), "Second is longfile parent\'s");
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testCountCommits($repository)
    {
        $log = $repository->getLog(self::LONGFILE_COMMIT, null, 2, 3);

        $this->assertEquals(8, $log->countCommits(), '8 commits found in history');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testCountAllCommits($repository)
    {
        $log = $log = $repository->getLog();

        $this->assertGreaterThan(100, $log->countCommits(), 'Returns all commits from all branches');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testIterable($repository)
    {
        $log = $repository->getLog(self::LONGFILE_COMMIT);

        $expectedHashes = [self::LONGFILE_COMMIT, self::BEFORE_LONGFILE_COMMIT];
        foreach ($log as $entry) {
            $hash = array_shift($expectedHashes);
            $this->assertEquals($hash, $entry->getHash());
            if (count($expectedHashes) == 0) {
                break;
            }
        }
    }

    public function testFirstMessageEmpty()
    {
        $repository = $this->createEmptyRepository(false);
        $repository->run('config', ['--local', 'user.name', '"Unit Test"']);
        $repository->run('config', ['--local', 'user.email', '"unit_test@unit-test.com"']);

        // Edge case: first commit lacks a message.
        file_put_contents($repository->getWorkingDir().'/file', 'foo');
        $repository->run('add', ['.']);
        $repository->run('commit', ['--allow-empty-message', '--no-edit']);

        $commits = $repository->getLog()->getCommits();
        $this->assertCount(1, $commits);
    }

    public function testParsesCommitsWithAndWithoutGitButlerHeaders(): void
    {
        $logContent = <<<'EOT'
  commit 1111111111111111111111111111111111111111
  tree abcdefabcdefabcdefabcdefabcdefabcdefabcd
  author John Doe <john@example.com> 1620000000 +0000
  committer John Doe <john@example.com> 1620000000 +0000
  
      First commit message
  
  commit 2222222222222222222222222222222222222222
  tree abcdefabcdefabcdefabcdefabcdefabcdefabcd
  parent 1111111111111111111111111111111111111111
  author Jane Smith <jane@example.com> 1620003600 +0000
  committer Jane Smith <jane@example.com> 1620003600 +0000
  gitbutler-headers-version: 2
  gitbutler-change-id: a7bd485c-bae6-45b2-910f-163c78aace81
  
      Commit with GitButler headers
  
  commit 3333333333333333333333333333333333333333
  tree abcdefabcdefabcdefabcdefabcdefabcdefabcd
  author John Doe <john@example.com> 1620007200 +0000
  committer Jane Smith <jane@example.com> 1620007200 +0000
  
      Another commit without GitButler headers
  
  EOT;

        $parser = new LogParser();
        $parser->parse($logContent);

        $log = $parser->log;
        $this->assertCount(3, $log);

        $this->assertEquals("First commit message\n", $log[0]['message']);
        $this->assertEquals("Commit with GitButler headers\n", $log[1]['message']);
        $this->assertEquals("Another commit without GitButler headers\n", $log[2]['message']);
    }

    /**
     * @dataProvider provideFoobar
     *
     * @group legacy
     */
    public function testCountWithALimit($repository)
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: Counting a log whose offset or limit leaves commits out with "Gitonomy\\Git\\Log::count()" is deprecated, it counts only the commits the log iterates in 2.0: use "countCommits()" to count them all.');

        $log = $repository->getLog(self::LONGFILE_COMMIT)->withLimit(2);

        $this->assertCount(8, $log);
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testCountWithALimitLeavingNoCommitOut($repository)
    {
        $this->assertCount(8, $repository->getLog(self::LONGFILE_COMMIT)->withOffset(0)->withLimit(10));
    }

    /**
     * @dataProvider provideFoobar
     *
     * @group legacy
     */
    public function testOptionAsRevision($repository)
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: Passing "--no-merges", an option of "git log", as revision to "Gitonomy\\Git\\Log::__construct()" is deprecated, it throws in 2.0: pass revisions, ranges, names to exclude or "--all".');

        $this->assertCount(1, $repository->getLog(['--no-merges', self::MERGE_COMMIT])->withLimit(1)->getCommits());
    }
}
