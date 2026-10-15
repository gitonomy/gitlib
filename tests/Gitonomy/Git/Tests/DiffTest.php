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

use Gitonomy\Git\Diff\Diff;
use Gitonomy\Git\Diff\DiffLine;
use Gitonomy\Git\Diff\DiffLineType;
use Gitonomy\Git\Diff\File;
use Gitonomy\Git\Diff\FileChange;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Repository;
use Symfony\Bridge\PhpUnit\ExpectDeprecationTrait;

class DiffTest extends AbstractTest
{
    use ExpectDeprecationTrait;

    const DELETE_COMMIT = '519d5693c72c925cd59205d9f11e9fa1d550028b';
    const CREATE_COMMIT = 'e6fa3c792facc06faa049a6938c84c411954deb5';
    const RENAME_COMMIT = '6640e0ef31518054847a1876328e26ee64083e0a';
    const CHANGEMODE_COMMIT = '93da965f58170f13017477b9a608657e87e23230';
    const FILE_WITH_UMLAUTS_COMMIT = '8defb9217692dc1f4c18e05e343ca91cf5047702';

    /**
     * @dataProvider provideFoobar
     */
    public function testSerialization($repository)
    {
        $data = $repository->getCommit(self::CREATE_COMMIT)->getDiff()->toArray();
        $diff = Diff::fromArray($data);

        $this->verifyCreateCommitDiff($diff);
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testGetFiles_Addition($repository)
    {
        $diff = $repository->getCommit(self::CREATE_COMMIT)->getDiff();
        $this->verifyCreateCommitDiff($diff);
    }

    protected function verifyCreateCommitDiff(Diff $diff)
    {
        $files = $diff->getFiles();

        $this->assertCount(2, $files, '1 file in diff');

        $this->assertTrue($files[0]->isCreation(), 'script_A.php created');

        $this->assertEquals(null, $files[0]->getOldName(), 'First file name is a new file');
        $this->assertEquals('script_A.php', $files[0]->getNewName(), 'First file name is script_A.php');
        $this->assertEquals(null, $files[0]->getOldMode(), 'First file mode is a new file');
        $this->assertEquals('100644', $files[0]->getNewMode(), 'First file mode is correct');

        $this->assertEquals(1, $files[0]->getAdditions(), '1 line added');
        $this->assertEquals(0, $files[0]->getDeletions(), '0 lines deleted');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testGetFiles_Modification($repository)
    {
        $files = $repository->getCommit(self::BEFORE_LONGFILE_COMMIT)->getDiff()->getFiles();

        $this->assertCount(1, $files, '1 files in diff');

        $this->assertTrue($files[0]->isModification(), 'image.jpg modified');

        $this->assertEquals('image.jpg', $files[0]->getOldName(), 'Second file name is image.jpg');
        $this->assertEquals('image.jpg', $files[0]->getNewName(), 'Second file name is image.jpg');
        $this->assertEquals('100644', $files[0]->getOldMode(), 'Second file mode is a new file');
        $this->assertEquals('100644', $files[0]->getNewMode(), 'Second file mode is correct');

        $this->assertTrue($files[0]->isBinary(), 'binary file');
        $this->assertEquals(0, $files[0]->getAdditions(), '0 lines added');
        $this->assertEquals(0, $files[0]->getDeletions(), '0 lines deleted');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testGetFiles_Deletion($repository)
    {
        $files = $repository->getCommit(self::DELETE_COMMIT)->getDiff()->getFiles();

        $this->assertCount(1, $files, '1 files modified');

        $this->assertTrue($files[0]->isDeletion(), 'File deletion');
        $this->assertEquals('script_B.php', $files[0]->getOldName(), 'verify old filename');
        $this->assertEquals(1, $files[0]->getDeletions(), '1 line deleted');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testGetFiles_Rename($repository)
    {
        $files = $repository->getCommit(self::RENAME_COMMIT)->getDiff()->getFiles();

        $this->assertCount(1, $files, '1 files modified');

        $this->assertTrue($files[0]->isModification());
        $this->assertTrue($files[0]->isRename());
        $this->assertFalse($files[0]->isDeletion());
        $this->assertFalse($files[0]->isCreation());
        $this->assertFalse($files[0]->isModeChange());
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testGetFiles_Changemode($repository)
    {
        $files = $repository->getCommit(self::CHANGEMODE_COMMIT)->getDiff()->getFiles();

        $this->assertCount(1, $files, '1 files modified');

        $this->assertTrue($files[0]->isModification());
        $this->assertTrue($files[0]->isModeChange());
        $this->assertFalse($files[0]->isDeletion());
        $this->assertFalse($files[0]->isCreation());
        $this->assertFalse($files[0]->isRename());
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testDiffRangeParse($repository)
    {
        $files = $repository->getCommit(self::CREATE_COMMIT)->getDiff()->getFiles();

        $changes = $files[0]->getChanges();

        $this->assertSame(0, $changes[0]->getRangeOldStart());
        $this->assertSame(0, $changes[0]->getRangeOldCount());

        $this->assertSame(1, $changes[0]->getRangeNewStart());
        $this->assertSame(1, $changes[0]->getRangeNewCount());
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testWorksWithUmlauts($repository)
    {
        $files = $repository->getCommit(self::FILE_WITH_UMLAUTS_COMMIT)->getDiff()->getFiles();
        $this->assertSame('file_with_umlauts_\303\244\303\266\303\274', $files[0]->getNewName());
    }

    /**
     * @group legacy
     */
    public function testDeleteFileWithoutRaw()
    {
        // Not silenced, like in 1.6.
        $this->expectDeprecation('Unsilenced deprecation: Using Diff::parse without raw information is deprecated. See https://github.com/gitonomy/gitlib/issues/227.');

        $diff = Diff::parse(<<<'DIFF'
        diff --git a/test b/test
        deleted file mode 100644
        index e69de29bb2d1d6434b8b29ae775ad8c2e48c5391..0000000000000000000000000000000000000000

        DIFF);
        $firstFile = $diff->getFiles()[0];

        $this->assertFalse($firstFile->isCreation());
        $this->assertTrue($firstFile->isDeletion());
        $this->assertFalse($firstFile->isModeChange());
        $this->assertSame('e69de29bb2d1d6434b8b29ae775ad8c2e48c5391', $firstFile->getOldIndex());
        $this->assertNull($firstFile->getNewIndex());
    }

    /**
     * @group legacy
     */
    public function testModeChangeFileWithoutRaw()
    {
        // Not silenced, like in 1.6.
        $this->expectDeprecation('Unsilenced deprecation: Using Diff::parse without raw information is deprecated. See https://github.com/gitonomy/gitlib/issues/227.');

        $diff = Diff::parse(<<<'DIFF'
        diff --git a/a.out b/a.out
        old mode 100755
        new mode 100644

        DIFF);
        $firstFile = $diff->getFiles()[0];

        $this->assertFalse($firstFile->isCreation());
        $this->assertFalse($firstFile->isDeletion());
        $this->assertTrue($firstFile->isModeChange());
        $this->assertSame('', $firstFile->getOldIndex());
        $this->assertSame('', $firstFile->getNewIndex());
    }

    public function testModeChangeFileWithRaw()
    {
        $diff = Diff::parse(<<<'DIFF'
        :100644 100755 d1af4b23d0cc9313e5b2d3ef2fb9696c94afaa81 d1af4b23d0cc9313e5b2d3ef2fb9696c94afaa81 M        testfile
        :100644 100755 d1af4b23d0cc9313e5b2d3ef2fb9696c94afaa82 d1af4b23d0cc9313e5b2d3ef2fb9696c94afaa82 M        testfile2

        diff --git a/testfile b/testfile
        old mode 100644
        new mode 100755
        diff --git a/testfile2 b/testfile2
        old mode 100644
        new mode 100755

        DIFF);
        $files = $diff->getFiles();
        $firstFile = $files[0];
        $secondFile = $files[1];

        $this->assertFalse($firstFile->isCreation());
        $this->assertFalse($firstFile->isDeletion());
        $this->assertTrue($firstFile->isModeChange());
        $this->assertSame('d1af4b23d0cc9313e5b2d3ef2fb9696c94afaa81', $firstFile->getOldIndex());
        $this->assertSame('d1af4b23d0cc9313e5b2d3ef2fb9696c94afaa81', $firstFile->getNewIndex());

        $this->assertFalse($secondFile->isCreation());
        $this->assertFalse($secondFile->isDeletion());
        $this->assertTrue($secondFile->isModeChange());
        $this->assertSame('d1af4b23d0cc9313e5b2d3ef2fb9696c94afaa82', $secondFile->getOldIndex());
        $this->assertSame('d1af4b23d0cc9313e5b2d3ef2fb9696c94afaa82', $secondFile->getNewIndex());
    }

    public function testThrowErrorOnBlobGetWithoutIndex()
    {
        $repository = $this->getMockBuilder(Repository::class)->disableOriginalConstructor()->getMock();
        $file = new File('oldName', 'newName', '100755', '100644', '', '', false, false, [], $repository);

        try {
            $file->getOldBlob();
        } catch(\RuntimeException $exception) {
            $this->assertSame('Index is missing to return Blob object.', $exception->getMessage());
        }

        try {
            $file->getNewBlob();
        } catch(\RuntimeException $exception) {
            $this->assertSame('Index is missing to return Blob object.', $exception->getMessage());
        }

        $this->assertFalse($file->isCreation());
        $this->assertFalse($file->isDeletion());
        $this->assertTrue($file->isModeChange());
        $this->assertSame('', $file->getOldIndex());
        $this->assertSame('', $file->getNewIndex());
    }

    /**
     * @group legacy
     */
    public function testEmptyNewFile()
    {
        // Not silenced, like in 1.6.
        $this->expectDeprecation('Unsilenced deprecation: Using Diff::parse without raw information is deprecated. See https://github.com/gitonomy/gitlib/issues/227.');

        $diff = Diff::parse("diff --git a/test b/test\nnew file mode 100644\nindex 0000000000000000000000000000000000000000..e69de29bb2d1d6434b8b29ae775ad8c2e48c5391\n");
        $firstFile = $diff->getFiles()[0];

        $this->assertTrue($firstFile->isCreation());
        $this->assertFalse($firstFile->isDeletion());
        $this->assertSame('test', $firstFile->getNewName());
        $this->assertNull($firstFile->getOldName());
    }

    /**
     * @group legacy
     */
    public function testEmptyOldFile()
    {
        // Not silenced, like in 1.6.
        $this->expectDeprecation('Unsilenced deprecation: Using Diff::parse without raw information is deprecated. See https://github.com/gitonomy/gitlib/issues/227.');

        $diff = Diff::parse("diff --git a/test b/test\ndeleted file mode 100644\nindex e69de29bb2d1d6434b8b29ae775ad8c2e48c5391..0000000000000000000000000000000000000000\n");
        $firstFile = $diff->getFiles()[0];

        $this->assertFalse($firstFile->isCreation());
        $this->assertTrue($firstFile->isDeletion());
        $this->assertNull($firstFile->getNewName());
        $this->assertSame('test', $firstFile->getOldName());
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testParseWithTheRepository($repository)
    {
        $raw = $repository->run('diff-tree', ['-r', '-p', '--raw', '-m', '-M', '--no-commit-id', '--full-index', self::CREATE_COMMIT]);

        $file = Diff::parse($raw, $repository)->getFiles()[0];

        $this->assertSame($repository, $file->getRepository());
        $this->assertSame("<?php echo 'a';\n", $file->getNewBlob()->getContent());
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testFromArrayWithTheRepository($repository)
    {
        $data = $repository->getCommit(self::CREATE_COMMIT)->getDiff()->toArray();

        $diff = Diff::fromArray($data, $repository);

        $this->verifyCreateCommitDiff($diff);
        $this->assertSame($repository, $diff->getFiles()[0]->getRepository());
        $this->assertSame($repository, File::fromArray($data['files'][0], $repository)->getRepository());
    }

    public function testConstructorTakesTheChangesAndTheRepository()
    {
        $repository = $this->getMockBuilder(Repository::class)->disableOriginalConstructor()->getMock();
        $change = new FileChange(1, 1, 1, 2, [new DiffLine(DiffLineType::Context, 'a'), new DiffLine(DiffLineType::Addition, 'b')]);

        $file = new File('a.txt', 'a.txt', '100644', '100644', 'abc', 'def', false, false, [$change], $repository);

        $this->assertSame([$change], $file->getChanges());
        $this->assertSame(1, $file->getAdditions());
        $this->assertSame(0, $file->getDeletions());
        $this->assertSame($repository, $file->getRepository());
        $this->assertFalse($file->isCopy());
    }

    public function testCopy()
    {
        $file = new File('a.txt', 'b.txt', '100644', '100644', 'abc', 'abc', false, true);

        $this->assertTrue($file->isCopy());
        $this->assertFalse($file->isRename());
        // Like in 1.6, toArray() has no "is_copy" key, which 2.0 adds: fromArray() reads it.
        $this->assertArrayNotHasKey('is_copy', $file->toArray());
        $this->assertTrue(File::fromArray(['is_copy' => true] + $file->toArray())->isCopy());
    }

    public function testFileChangeCountsTheLines()
    {
        $change = new FileChange(1, 2, 1, 2, [new DiffLine(DiffLineType::Deletion, 'a'), new DiffLine(DiffLineType::Addition, 'b'), new DiffLine(DiffLineType::Addition, 'c'), new DiffLine(DiffLineType::Context, 'd')]);

        $this->assertSame(2, $change->getAdditions());
        $this->assertSame(1, $change->getDeletions());
    }

    public function testFileChangeTakesDiffLines()
    {
        $lines = [new DiffLine(DiffLineType::Deletion, 'a'), new DiffLine(DiffLineType::Addition, 'b'), new DiffLine(DiffLineType::Context, 'c')];

        $change = new FileChange(1, 2, 1, 2, $lines);

        $this->assertEquals($lines, $change->getDiffLines());
        $this->assertSame([[-1, 'a'], [1, 'b'], [0, 'c']], $change->toArray()['lines']);
        $this->assertEquals($lines, FileChange::fromArray($change->toArray())->getDiffLines());
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testGetDiffLines($repository)
    {
        $change = $repository->getCommit(self::LONGFILE_COMMIT)->getDiff()->getFiles()[0]->getChanges()[0];

        $lines = $change->getDiffLines();

        $this->assertCount($change->getRangeNewCount(), $lines);
        $this->assertSame(DiffLineType::Addition, $lines[0]->getType());
        $this->assertSame(array_map(static fn (DiffLine $line): array => [$line->getType()->value, $line->getContent()], $lines), $change->toArray()['lines']);
    }

    public function testGetDiffLinesThrowsForAnUnknownType()
    {
        $change = FileChange::fromArray(['range_old_start' => 1, 'range_old_count' => 1, 'range_new_start' => 1, 'range_new_count' => 1, 'lines' => [[5, 'a']]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown type of line 5: the types are 1 (an addition), 0 (a context line) and -1 (a deletion).');

        $change->getDiffLines();
    }

    /**
     * @group legacy
     */
    public function testFileChangeLinesAsArrays()
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: Passing the lines to "Gitonomy\Git\Diff\FileChange::__construct()" as arrays is deprecated, pass "Gitonomy\Git\Diff\DiffLine" objects instead.');
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Diff\FileChange::getLines()" method is deprecated, use "getDiffLines()" instead.');

        $change = new FileChange(1, 1, 1, 2, [[FileChange::LINE_CONTEXT, 'a'], [FileChange::LINE_ADD, 'b']]);

        $this->assertSame([[0, 'a'], [1, 'b']], $change->getLines());
        $this->assertEquals([new DiffLine(DiffLineType::Context, 'a'), new DiffLine(DiffLineType::Addition, 'b')], $change->getDiffLines());
        $this->assertSame(1, $change->getAdditions());
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testGetDiffBetweenTwoRevisions($repository)
    {
        $diff = $repository->getDiff(self::BEFORE_LONGFILE_COMMIT, $repository->getCommit(self::LONGFILE_COMMIT));

        $this->assertSame($repository->getCommit(self::LONGFILE_COMMIT)->getDiff()->toArray()['files'], $diff->toArray()['files']);
        $this->assertSame($repository, $diff->getFiles()[0]->getRepository());
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testGetDiffRefusesAListOfRevisionsWithTo($repository)
    {
        $this->expectException(InvalidArgumentException::class);

        $repository->getDiff([self::BEFORE_LONGFILE_COMMIT], self::LONGFILE_COMMIT);
    }

    /**
     * @dataProvider provideFoobar
     *
     * @group legacy
     */
    public function testGetDiffWithOneArgument($repository)
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: Calling "Gitonomy\Git\Repository::getDiff()" with one argument is deprecated, pass the revisions to compare as first and second arguments, or use "Gitonomy\Git\WorkingCopy::getDiff()" to compare a revision with the working tree.');

        $diff = $repository->getDiff(self::BEFORE_LONGFILE_COMMIT.'..'.self::LONGFILE_COMMIT);

        $this->assertSame($repository->getDiff(self::BEFORE_LONGFILE_COMMIT, self::LONGFILE_COMMIT)->toArray(), $diff->toArray());
    }

    /**
     * @dataProvider provideFoobar
     *
     * @group legacy
     */
    public function testLogGetDiff($repository)
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Log::getDiff()" method is deprecated, use "Gitonomy\Git\Repository::getDiff()" instead.');

        $diff = $repository->getLog(self::BEFORE_LONGFILE_COMMIT.'..'.self::LONGFILE_COMMIT)->getDiff();

        $this->assertSame($repository->getDiff(self::BEFORE_LONGFILE_COMMIT, self::LONGFILE_COMMIT)->toArray(), $diff->toArray());
    }

    /**
     * @group legacy
     */
    public function testSetRepository()
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Diff\Diff::setRepository()" method is deprecated, the diffs gitlib returns have their repository already.');
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Diff\File::setRepository()" method is deprecated, the diffs gitlib returns have their repository already.');

        $repository = $this->getMockBuilder(Repository::class)->disableOriginalConstructor()->getMock();
        $diff = new Diff([new File('a.txt', 'a.txt', '100644', '100644', 'abc', 'def', false)], '');
        $diff->setRepository($repository);

        $this->assertSame($repository, $diff->getFiles()[0]->getRepository());

        $file = new File('a.txt', 'a.txt', '100644', '100644', 'abc', 'def', false);
        $file->setRepository($repository);

        $this->assertSame($repository, $file->getRepository());
    }

    /**
     * @group legacy
     */
    public function testAddChange()
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Diff\File::addChange()" method is deprecated, give the changes to the constructor instead.');

        $file = new File('a.txt', 'a.txt', '100644', '100644', 'abc', 'def', false);
        $change = new FileChange(1, 1, 1, 1, [new DiffLine(DiffLineType::Addition, 'a')]);
        $file->addChange($change);

        $this->assertSame([$change], $file->getChanges());
    }

    /**
     * @group legacy
     */
    public function testDeprecatedNames()
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Diff\File::isChangeMode()" method is deprecated, use "isModeChange()" instead.');
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Diff\File::isDelete()" method is deprecated, use "isDeletion()" instead.');
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Diff\File::getAnchor()" method is deprecated, use "substr($file->getNewIndex(), 0, 12)" instead.');
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Diff\FileChange::getCount()" method is deprecated, use "getAdditions()" or "getDeletions()" instead.');

        $file = new File('a.txt', null, '100755', null, 'abcdef0123456789', null, false);

        $this->assertFalse($file->isChangeMode());
        $this->assertTrue($file->isDelete());
        $this->assertSame('', $file->getAnchor());
        $this->assertSame('0123456789ab', (new File('a.txt', 'a.txt', '100644', '100644', 'abc', '0123456789abcdef', false))->getAnchor());
        $this->assertSame(1, (new FileChange(1, 1, 1, 1, [new DiffLine(DiffLineType::Addition, 'a')]))->getCount(DiffLineType::Addition->value));
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testGetDiffThrowsForAnUnknownRevision($repository)
    {
        $this->expectException(ReferenceNotFoundException::class);

        $repository->getDiff(self::LONGFILE_COMMIT, 'unknown');
    }
}
