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

use Gitonomy\Git\Diff\Diff;
use Gitonomy\Git\Diff\DiffLine;
use Gitonomy\Git\Diff\DiffLineType;
use Gitonomy\Git\Diff\File;
use Gitonomy\Git\Diff\FileChange;
use Gitonomy\Git\Driver\Ffi\LibGit2Driver;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\LogicException;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

class DiffTest extends AbstractTestCase
{
    public const DELETE_COMMIT = '519d5693c72c925cd59205d9f11e9fa1d550028b';
    public const CREATE_COMMIT = 'e6fa3c792facc06faa049a6938c84c411954deb5';
    public const RENAME_COMMIT = '6640e0ef31518054847a1876328e26ee64083e0a';
    public const CHANGEMODE_COMMIT = '93da965f58170f13017477b9a608657e87e23230';
    public const FILE_WITH_UMLAUTS_COMMIT = '8defb9217692dc1f4c18e05e343ca91cf5047702';

    #[DataProvider('provideBare')]
    public function testSerialization(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $data = $repository->getCommit(self::CREATE_COMMIT)->getDiff()->toArray();
        $diff = Diff::fromArray($data);

        $this->verifyCreateCommitDiff($diff);
    }

    #[DataProvider('provideBare')]
    public function testGetFilesAddition(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $diff = $repository->getCommit(self::CREATE_COMMIT)->getDiff();
        $this->verifyCreateCommitDiff($diff);
    }

    #[DataProvider('provideBare')]
    public function testGetFilesModification(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $files = $repository->getCommit(self::BEFORE_LONGFILE_COMMIT)->getDiff()->getFiles();

        $this->assertCount(1, $files, '1 files in diff');

        $this->assertTrue($files[0]->isModification(), 'image.jpg modified');

        $this->assertSame('image.jpg', $files[0]->getOldName(), 'Old file name is image.jpg');
        $this->assertSame('image.jpg', $files[0]->getNewName(), 'New file name is image.jpg');
        $this->assertSame('100644', $files[0]->getOldMode(), 'Old file mode is a regular file');
        $this->assertSame('100644', $files[0]->getNewMode(), 'New file mode is a regular file');

        $this->assertTrue($files[0]->isBinary(), 'binary file');
        $this->assertSame(0, $files[0]->getAdditions(), '0 lines added');
        $this->assertSame(0, $files[0]->getDeletions(), '0 lines deleted');
    }

    #[DataProvider('provideBare')]
    public function testGetFilesDeletion(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $files = $repository->getCommit(self::DELETE_COMMIT)->getDiff()->getFiles();

        $this->assertCount(1, $files, '1 files modified');

        $this->assertTrue($files[0]->isDeletion(), 'File deletion');
        $this->assertSame('script_B.php', $files[0]->getOldName(), 'verify old filename');
        $this->assertSame(1, $files[0]->getDeletions(), '1 line deleted');
    }

    #[DataProvider('provideBare')]
    public function testGetFilesRename(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $files = $repository->getCommit(self::RENAME_COMMIT)->getDiff()->getFiles();

        $this->assertCount(1, $files, '1 files modified');

        $this->assertTrue($files[0]->isModification());
        $this->assertTrue($files[0]->isRename());
        $this->assertFalse($files[0]->isDeletion());
        $this->assertFalse($files[0]->isCreation());
        $this->assertFalse($files[0]->isModeChange());
    }

    #[DataProvider('provideBare')]
    public function testGetFilesChangemode(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $files = $repository->getCommit(self::CHANGEMODE_COMMIT)->getDiff()->getFiles();

        $this->assertCount(1, $files, '1 files modified');

        $this->assertTrue($files[0]->isModification());
        $this->assertTrue($files[0]->isModeChange());
        $this->assertFalse($files[0]->isDeletion());
        $this->assertFalse($files[0]->isCreation());
        $this->assertFalse($files[0]->isRename());
    }

    #[DataProvider('provideBare')]
    public function testDiffRangeParse(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $files = $repository->getCommit(self::CREATE_COMMIT)->getDiff()->getFiles();

        $changes = $files[0]->getChanges();

        $this->assertSame(0, $changes[0]->getRangeOldStart());
        $this->assertSame(0, $changes[0]->getRangeOldCount());

        $this->assertSame(1, $changes[0]->getRangeNewStart());
        $this->assertSame(1, $changes[0]->getRangeNewCount());
    }

    #[DataProvider('provideBare')]
    public function testRootCommitDiffListsTheFilesItAdds(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $files = $repository->getCommit(self::INITIAL_COMMIT)->getDiff()->getFiles();

        $this->assertCount(1, $files);
        $this->assertTrue($files[0]->isCreation());
        $this->assertSame('README', $files[0]->getNewName());
    }

    #[DataProvider('provideBare')]
    public function testWorksWithUmlauts(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $files = $repository->getCommit(self::FILE_WITH_UMLAUTS_COMMIT)->getDiff()->getFiles();
        $this->assertSame('file_with_umlauts_äöü', $files[0]->getNewName());
    }

    public function testUnquotesNames(): void
    {
        $diff = Diff::parse(<<<'DIFF'
            :000000 100644 0000000000000000000000000000000000000000 e69de29bb2d1d6434b8b29ae775ad8c2e48c5391 A	"tab\there"
            :100644 100644 e69de29bb2d1d6434b8b29ae775ad8c2e48c5391 d00491fd7e5bb6fa28c517a0bb32b8b506539d4d M	"caf\303\251 \"au lait\""

            diff --git "a/tab\there" "b/tab\there"
            new file mode 100644
            index 0000000000000000000000000000000000000000..e69de29bb2d1d6434b8b29ae775ad8c2e48c5391
            diff --git "a/caf\303\251 \"au lait\"" "b/caf\303\251 \"au lait\""
            index e69de29bb2d1d6434b8b29ae775ad8c2e48c5391..d00491fd7e5bb6fa28c517a0bb32b8b506539d4d 100644
            Binary files "a/caf\303\251 \"au lait\"" and "b/caf\303\251 \"au lait\"" differ

            DIFF);
        $files = $diff->getFiles();

        $this->assertSame("tab\there", $files[0]->getNewName());
        $this->assertSame('café "au lait"', $files[1]->getOldName());
        $this->assertSame('café "au lait"', $files[1]->getNewName());
    }

    public function testDeleteFile(): void
    {
        $diff = Diff::parse(<<<'DIFF'
            :100644 000000 e69de29bb2d1d6434b8b29ae775ad8c2e48c5391 0000000000000000000000000000000000000000 D	test

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

    public function testModeChangeFile(): void
    {
        $diff = Diff::parse(<<<'DIFF'
            :100644 100755 d1af4b23d0cc9313e5b2d3ef2fb9696c94afaa81 d1af4b23d0cc9313e5b2d3ef2fb9696c94afaa81 M	testfile
            :100644 100755 d1af4b23d0cc9313e5b2d3ef2fb9696c94afaa82 d1af4b23d0cc9313e5b2d3ef2fb9696c94afaa82 M	testfile2

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

    public function testCopyFile(): void
    {
        $diff = Diff::parse(<<<'DIFF'
            :100644 100644 1234567890123456789012345678901234567890 89abcdef0123456789abcdef0123456789abcdef C097	style.css	common.css

            diff --git a/style.css b/common.css
            similarity index 97%
            copy from style.css
            copy to common.css
            index 1234567..89abcde 100644
            --- a/style.css
            +++ b/common.css
            @@ -1,3 +1,4 @@
             body {
            +  color: red;
             }

            DIFF);
        $firstFile = $diff->getFiles()[0];

        $this->assertTrue($firstFile->isModification());
        $this->assertTrue($firstFile->isCopy());
        $this->assertFalse($firstFile->isRename());
        $this->assertFalse($firstFile->isDeletion());
        $this->assertFalse($firstFile->isCreation());
        $this->assertSame('style.css', $firstFile->getOldName());
        $this->assertSame('common.css', $firstFile->getNewName());
        $this->assertSame(1, $firstFile->getAdditions());
    }

    public function testPureCopyFile(): void
    {
        $diff = Diff::parse(<<<'DIFF'
            :100644 100644 1234567890123456789012345678901234567890 1234567890123456789012345678901234567890 C100	style.css	common.css

            diff --git a/style.css b/common.css
            similarity index 100%
            copy from style.css
            copy to common.css

            DIFF);
        $firstFile = $diff->getFiles()[0];

        $this->assertTrue($firstFile->isCopy());
        $this->assertFalse($firstFile->isRename());
        $this->assertSame('style.css', $firstFile->getOldName());
        $this->assertSame('common.css', $firstFile->getNewName());
    }

    public function testParseRequiresTheRawInformation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The diff has no raw information: Diff::parse() parses the output of "git diff --raw --patch".');

        Diff::parse("diff --git a/a.out b/a.out\nold mode 100755\nnew mode 100644\n");
    }

    /**
     * What the parser doesn't know would be lost, with the files after it.
     * The message has the start of its line only, which may come from a file.
     */
    public function testParseRefusesWhatItDoesNotKnow(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to parse the diff from "Submodule sub 1111111..2222222:".');

        Diff::parse(":160000 160000 1111111111111111111111111111111111111111 2222222222222222222222222222222222222222 M\tsub\n\nSubmodule sub 1111111..2222222:\n  > Second\n");
    }

    /**
     * "git diff --staged" during a conflict, whose unmerged path comes last.
     */
    public function testParseLeavesOutTheLastUnmergedPath(): void
    {
        $diff = Diff::parse(<<<'DIFF'
            :000000 100644 0000000000000000000000000000000000000000 e45c9c2666d44e0327c1f9c239a74c508336053e A	other
            :100644 000000 ba2906d0666cf726c7eaadd2cd3db615dedfdf3a 0000000000000000000000000000000000000000 U	zconflict

            diff --git a/other b/other
            new file mode 100644
            index 0000000000000000000000000000000000000000..e45c9c2666d44e0327c1f9c239a74c508336053e
            --- /dev/null
            +++ b/other
            @@ -0,0 +1 @@
            +other
            * Unmerged path zconflict

            DIFF);

        $this->assertSame([['other', [[0, 0, 1, 1, [['Addition', 'other']]]]]], self::changes($diff));
    }

    public function testParseAnEmptyDiff(): void
    {
        $this->assertSame([], Diff::parse('')->getFiles());
    }

    public function testDiffOfAMergeCommitIsAgainstItsFirstParent(): void
    {
        $repository = self::createWorkingCopy();
        self::commitFile($repository, 'base', "base\n");
        self::git($repository, 'checkout', ['-q', '-b', 'side']);
        self::commitFile($repository, 'side', "side\n");
        self::git($repository, 'checkout', ['-q', 'master']);
        self::commitFile($repository, 'main', "main\n");
        self::git($repository, 'merge', ['-q', '--no-edit', 'side']);

        $files = $repository->getHeadCommit()->getDiff()->getFiles();

        $this->assertSame(['side'], array_map(static fn (File $file): string => $file->getName(), $files));
    }

    public function testNamesWithSpaces(): void
    {
        $repository = self::createWorkingCopy();
        self::commitFile($repository, 'Vol 1/chapter.txt', "a\n");
        self::commitFile($repository, 'cats and dogs.bin', "\0a");
        self::commitFile($repository, 'Vol 1/chapter.txt', "a\nb\n");
        self::commitFile($repository, 'cats and dogs.bin', "\0b");

        $diff = $repository->getDiff('HEAD~2', 'HEAD');

        $this->assertSame(['Vol 1/chapter.txt', 'cats and dogs.bin'], array_map(static fn (File $file): string => $file->getName(), $diff->getFiles()));
        $this->assertTrue($diff->getFiles()[1]->isBinary());
        $this->assertSame("a\nb\n", $diff->getFiles()[0]->getNewBlob()->getContent());
    }

    public function testTypeChangeIsADeletionAndACreation(): void
    {
        $repository = self::createWorkingCopy();
        self::commitFile($repository, 'link', "target\n");
        self::commitFile($repository, 'renamed', "moved\n");
        unlink($repository->getWorkingDir().'/link');
        symlink('target', $repository->getWorkingDir().'/link');
        self::git($repository, 'mv', ['renamed', 'moved']);
        self::git($repository, 'add', ['-A']);
        self::git($repository, 'commit', ['-q', '-m', 'Type change']);

        $files = $repository->getHeadCommit()->getDiff()->getFiles();

        $this->assertCount(3, $files);
        $this->assertTrue($files[0]->isDeletion());
        $this->assertSame("target\n", $files[0]->getOldBlob()->getContent());
        $this->assertTrue($files[1]->isCreation());
        $this->assertSame('120000', $files[1]->getNewMode());
        $this->assertSame('target', $files[1]->getNewBlob()->getContent());
        // The hashes of a rename without changes come from the raw information.
        $this->assertTrue($files[2]->isRename());
        $this->assertSame("moved\n", $files[2]->getNewBlob()->getContent());
    }

    public function testDiffsOfAConflictLeaveUnmergedPathsOut(): void
    {
        $repository = self::createWorkingCopy();
        self::commitFile($repository, 'conflict', "base\n");
        self::git($repository, 'checkout', ['-q', '-b', 'side']);
        self::commitFile($repository, 'conflict', "side\n");
        self::git($repository, 'checkout', ['-q', 'master']);
        self::commitFile($repository, 'conflict', "main\n");

        try {
            self::git($repository, 'merge', ['-q', 'side']);
        } catch (ProcessException) {
            // The conflict.
        }
        file_put_contents($repository->getWorkingDir().'/other', "other\n");
        self::git($repository, 'add', ['other']);

        $this->assertSame(['other'], array_map(static fn (File $file): string => $file->getName(), $repository->getWorkingCopy()->getDiffStaged()->getFiles()));
        $this->assertSame([], $repository->getWorkingCopy()->getDiffPending()->getFiles());

        // Like git diff HEAD, which compares the unmerged file, conflict markers included, to HEAD.
        $files = $repository->getWorkingCopy()->getDiff()->getFiles();
        $this->assertSame(['conflict', 'other'], array_map(static fn (File $file): string => $file->getName(), $files));
        $this->assertContains('<<<<<<< HEAD', array_map(static fn (DiffLine $line): string => $line->getContent(), $files[0]->getChanges()[0]->getLines()));
    }

    public function testWorkingCopyDiff(): void
    {
        $repository = self::createWorkingCopy();
        self::commitFile($repository, 'file', "a\n");
        file_put_contents($repository->getWorkingDir().'/file', "a\nb\n");

        $files = $repository->getWorkingCopy()->getDiff()->getFiles();

        $this->assertCount(1, $files);
        $this->assertSame(1, $files[0]->getAdditions());
        $this->assertSame([DiffLineType::Context, DiffLineType::Addition], array_map(static fn (DiffLine $line): DiffLineType => $line->getType(), $files[0]->getChanges()[0]->getLines()));
        $this->assertSame('b', $files[0]->getChanges()[0]->getLines()[1]->getContent());
    }

    public function testHunkWithOneLine(): void
    {
        $diff = Diff::parse(":100644 100644 7898192261 422c2b7ab3 M\tfile\n\ndiff --git a/file b/file\nindex 7898192261..422c2b7ab3 100644\n--- a/file\n+++ b/file\n@@ -1 +1,2 @@\n a\n+b\n");
        $change = $diff->getFiles()[0]->getChanges()[0];

        $this->assertSame([1, 1, 1, 2], [$change->getRangeOldStart(), $change->getRangeOldCount(), $change->getRangeNewStart(), $change->getRangeNewCount()]);
    }

    public function testParseNulSeparatedRawInformation(): void
    {
        $raw = ":100644 100644 7898192261 422c2b7ab3 R087\0old name\0new name\0:000000 100644 0000000000 e69de29bb2 A\0new\0";
        $files = Diff::parse($raw)->getFiles();

        $this->assertSame(['old name', 'new name'], [$files[0]->getOldName(), $files[0]->getNewName()]);
        $this->assertTrue($files[0]->isRename());
        $this->assertTrue($files[1]->isCreation());
        $this->assertSame('e69de29bb2', $files[1]->getNewIndex());
    }

    public function testParseQuotedNames(): void
    {
        $files = Diff::parse(":100644 100644 7898192261 422c2b7ab3 M\t\"caf\\303\\251\\t.txt\"\n")->getFiles();

        $this->assertSame("caf\u{e9}\t.txt", $files[0]->getName());
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyGetDiffOfARange(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: Calling "Gitonomy\Git\Repository::getDiff()" with one argument is deprecated, pass the revisions to compare as "from" and "to" arguments, or use "Gitonomy\Git\WorkingCopy::getDiff()" to compare a revision with the working tree.');

        $repository = self::createFoobarRepository();

        $this->assertSame($repository->getDiff(self::BEFORE_LONGFILE_COMMIT, self::LONGFILE_COMMIT)->toArray(), $repository->getDiff(self::BEFORE_LONGFILE_COMMIT.'..'.self::LONGFILE_COMMIT)->toArray());
        $this->assertSame($repository->getDiff(self::BEFORE_LONGFILE_COMMIT, self::LONGFILE_COMMIT)->toArray(), $repository->getDiff([self::BEFORE_LONGFILE_COMMIT, self::LONGFILE_COMMIT])->toArray());
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyLinesAsArrays(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: Reading a "Gitonomy\Git\Diff\DiffLine" as an array is deprecated, use its "getType()" and "getContent()" methods instead.');

        $line = new DiffLine(DiffLineType::Addition, 'added');
        [$type, $content] = $line;

        $this->assertSame([FileChange::LINE_ADD, 'added'], [$type, $content]);
    }

    public function testFromArrayRefusesAnUnknownTypeOfLine(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown type of line 2: the types are FileChange::LINE_ADD, LINE_CONTEXT and LINE_REMOVE.');

        FileChange::fromArray(['range_old_start' => 1, 'range_old_count' => 1, 'range_new_start' => 1, 'range_new_count' => 1, 'lines' => [[2, 'line']]]);
    }

    public function testRawDiffIsTheOutputOfGit(): void
    {
        $diff = self::createFoobarRepository()->getCommit(self::LONGFILE_COMMIT)->getDiff();

        if (self::getDriver() instanceof LibGit2Driver) {
            // No git command computed it.
            $this->assertSame('', $diff->getRawDiff());
        } else {
            $this->assertStringContainsString("\0diff --git a/long.php b/long.php\n", $diff->getRawDiff());
        }
    }

    public function testGetBlobWithoutHashThrows(): void
    {
        $file = new File('sub', 'sub', '160000', '160000', null, null, false, repository: self::createEmptyRepository());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The diff doesn\'t know the hash of the new blob of "sub".');

        $file->getNewBlob();
    }

    public function testGetBlobWithoutRepositoryThrows(): void
    {
        $file = Diff::parse(":100644 100644 e69de29bb2d1d6434b8b29ae775ad8c2e48c5391 d1af4b23d0cc9313e5b2d3ef2fb9696c94afaa81 M\tfile\n")->getFiles()[0];

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A diff without repository can\'t read blobs: get it from a repository.');

        $file->getOldBlob();
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacySetRepository(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Diff\File::setRepository()" method is deprecated, the diffs gitlib returns have their repository already.');

        $repository = self::createFoobarRepository();
        $file = new File('README.md', 'README.md', '100644', '100644', null, null, false);
        $file->setRepository($repository);

        $this->assertSame($repository, $file->getRepository());
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyDiffSetRepository(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Diff\Diff::setRepository()" method is deprecated, the diffs gitlib returns have their repository already.');

        $repository = self::createFoobarRepository();
        $diff = Diff::parse(":100644 100644 1111111111111111111111111111111111111111 2222222222222222222222222222222222222222 M\tREADME.md\n");
        $diff->setRepository($repository);

        $this->assertSame($repository, $diff->getFiles()[0]->getRepository());
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyAddChange(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Diff\File::addChange()" method is deprecated, give the changes to the constructor instead.');

        $file = new File('README.md', 'README.md', '100644', '100644', null, null, false);
        $change = new FileChange(1, 1, 1, 1, [new DiffLine(DiffLineType::Addition, 'added')]);
        $file->addChange($change);

        $this->assertSame([$change], $file->getChanges());
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyIsChangeMode(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Diff\File::isChangeMode()" method is deprecated, use "isModeChange()" instead.');

        $this->assertTrue(new File('run.sh', 'run.sh', '100644', '100755', null, null, false)->isChangeMode());
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyFileIsDelete(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Diff\File::isDelete()" method is deprecated, use "isDeletion()" instead.');

        $this->assertTrue(new File('README.md', null, '100644', null, null, null, false)->isDelete());
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyGetAnchor(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Diff\File::getAnchor()" method is deprecated, use "substr($file->getNewIndex(), 0, 12)" instead.');

        $this->assertSame('222222222222', new File('README.md', 'README.md', '100644', '100644', str_repeat('1', 40), str_repeat('2', 40), false)->getAnchor());
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyGetCount(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Diff\FileChange::getCount()" method is deprecated, use "getAdditions()" or "getDeletions()" instead.');

        $change = new FileChange(1, 1, 1, 2, [new DiffLine(DiffLineType::Context, 'kept'), new DiffLine(DiffLineType::Addition, 'added')]);

        $this->assertSame(1, $change->getCount(FileChange::LINE_ADD));
    }

    public function testMnemonicPrefix(): void
    {
        // With `diff.mnemonicPrefix` enabled, git replaces the default "a/" and "b/"
        // prefixes with context-specific ones, e.g. "c/" (commit) and "i/" (index)
        // for `git diff --cached`. See https://github.com/gitonomy/gitlib/issues/114.
        $diff = Diff::parse(<<<'DIFF'
            :100644 100644 0000000000000000000000000000000000000000 e69de29bb2d1d6434b8b29ae775ad8c2e48c5391 M	composer.json

            diff --git c/composer.json i/composer.json
            index 0000000000000000000000000000000000000000..e69de29bb2d1d6434b8b29ae775ad8c2e48c5391 100644
            --- c/composer.json
            +++ i/composer.json

            DIFF);
        $firstFile = $diff->getFiles()[0];

        $this->assertSame('composer.json', $firstFile->getOldName());
        $this->assertSame('composer.json', $firstFile->getNewName());
    }

    public function testEmptyNewFile(): void
    {
        $diff = Diff::parse(":000000 100644 0000000000000000000000000000000000000000 e69de29bb2d1d6434b8b29ae775ad8c2e48c5391 A\ttest\n\ndiff --git a/test b/test\nnew file mode 100644\nindex 0000000000000000000000000000000000000000..e69de29bb2d1d6434b8b29ae775ad8c2e48c5391\n");
        $firstFile = $diff->getFiles()[0];

        $this->assertTrue($firstFile->isCreation());
        $this->assertFalse($firstFile->isDeletion());
        $this->assertSame('test', $firstFile->getNewName());
        $this->assertNull($firstFile->getOldName());
    }

    public function testEmptyOldFile(): void
    {
        $diff = Diff::parse(":100644 000000 e69de29bb2d1d6434b8b29ae775ad8c2e48c5391 0000000000000000000000000000000000000000 D\ttest\n\ndiff --git a/test b/test\ndeleted file mode 100644\nindex e69de29bb2d1d6434b8b29ae775ad8c2e48c5391..0000000000000000000000000000000000000000\n");
        $firstFile = $diff->getFiles()[0];

        $this->assertFalse($firstFile->isCreation());
        $this->assertTrue($firstFile->isDeletion());
        $this->assertNull($firstFile->getNewName());
        $this->assertSame('test', $firstFile->getOldName());
    }

    public function testDirtySubmodule(): void
    {
        $diff = Diff::parse(<<<'DIFF'
            :160000 160000 e69de29bb2d1d6434b8b29ae775ad8c2e48c5391 0000000000000000000000000000000000000000 M	sub

            diff --git a/sub b/sub
            --- a/sub
            +++ b/sub
            @@ -1 +1 @@
            -Subproject commit e69de29bb2d1d6434b8b29ae775ad8c2e48c5391
            +Subproject commit e69de29bb2d1d6434b8b29ae775ad8c2e48c5391-dirty

            DIFF);
        $firstFile = $diff->getFiles()[0];

        $this->assertFalse($firstFile->isCreation());
        $this->assertFalse($firstFile->isDeletion());
        $this->assertSame('sub', $firstFile->getOldName());
        $this->assertSame('sub', $firstFile->getNewName());
    }

    /**
     * With diff.submodule=log, which the configuration of the tests sets, "git
     * diff" writes the log of a submodule instead of its patch.
     */
    public function testTheFilesAfterASubmoduleKeepTheirPatch(): void
    {
        $repository = self::createWorkingCopy();
        $submodule = Repository::init($repository->getWorkingDir().'/sub', self::getCliDriver());
        self::git($submodule, 'config', ['user.name', 'Unit Test']);
        self::git($submodule, 'config', ['user.email', 'unit_test@unit-test.com']);
        self::git($submodule, 'commit', ['-q', '--allow-empty', '-m', 'First']);
        $first = trim(self::git($submodule, 'rev-parse', ['HEAD']));
        self::git($repository, 'update-index', ['--add', '--cacheinfo', '160000', $first, 'sub']);
        self::commitFile($repository, 'z.txt', "a\n");

        self::git($submodule, 'commit', ['-q', '--allow-empty', '-m', 'Second']);
        $second = trim(self::git($submodule, 'rev-parse', ['HEAD']));
        file_put_contents($repository->getWorkingDir().'/z.txt', "b\n");

        $diff = $repository->getWorkingCopy()->getDiffPending();

        $this->assertSame([
            ['sub', [[1, 1, 1, 1, [['Deletion', 'Subproject commit '.$first], ['Addition', 'Subproject commit '.$second]]]]],
            ['z.txt', [[1, 1, 1, 1, [['Deletion', 'a'], ['Addition', 'b']]]]],
        ], self::changes($diff));
        $this->assertSame(sha1("blob 2\0b\n"), $diff->getFiles()[1]->getNewIndex());
    }

    /**
     * Unlike diff-tree, "git diff" reads diff.algorithm, diff.context,
     * diff.orderFile... which the configuration of the tests changes (see
     * fixtures/home/.gitconfig): the diff of the working tree is the one of
     * its commit all the same.
     */
    public function testTheDiffOfTheWorkingTreeIsTheOneOfItsCommit(): void
    {
        $repository = self::createWorkingCopy();
        // Ten lines between two changes: two hunks.
        $lines = array_map(static fn (int $number): string => 'line '.$number, range(1, 20));
        self::commitFile($repository, 'notes.txt', implode("\n", $lines)."\n");
        // The diff algorithm of git keeps "{", histogram keeps "}".
        self::commitFile($repository, 'code.c', "c\n}\n{\n");

        $lines[2] = 'line 3, changed';
        $lines[13] = 'line 14, changed';
        file_put_contents($repository->getWorkingDir().'/notes.txt', implode("\n", $lines)."\n");
        file_put_contents($repository->getWorkingDir().'/code.c', "c\n{\nc\n}\n");

        $workingTreeDiff = $repository->getWorkingCopy()->getDiff();
        self::git($repository, 'commit', ['-q', '-a', '-m', 'Changes']);
        $commitDiff = $repository->getHeadCommit()->getDiff();

        $this->assertSame(['code.c', 'notes.txt'], array_map(static fn (File $file): string => $file->getName(), $commitDiff->getFiles()));
        $this->assertSame(self::changes($commitDiff), self::changes($workingTreeDiff));
    }

    /**
     * The indent heuristic of git, which diff.indentHeuristic turns off,
     * places an added block after the blank line that ends it.
     */
    public function testTheIndentHeuristicIsGits(): void
    {
        if (version_compare(self::gitVersion(), '2.11.0', '<')) {
            $this->markTestSkipped('git has an indent heuristic since 2.11.');
        }

        $repository = self::createWorkingCopy();
        self::commitFile($repository, 'code.c', "\n\nfoo();\n");
        self::commitFile($repository, 'code.c', "\n\nfoo();\n\nfoo();\n");

        $this->assertSame([['code.c', [[1, 3, 1, 5, [
            ['Context', ''],
            ['Context', ''],
            ['Addition', 'foo();'],
            ['Addition', ''],
            ['Context', 'foo();'],
        ]]]]], self::changes($repository->getHeadCommit()->getDiff()));
    }

    /**
     * "git diff" reads diff.noprefix, diff.mnemonicPrefix, diff.srcPrefix and
     * diff.dstPrefix too: a patch to apply has the prefixes of git.
     */
    public function testTheRawDiffOfTheWorkingTreeHasThePrefixesOfGit(): void
    {
        $this->skipUnlessCliDriver();

        $repository = self::createWorkingCopy();
        self::commitFile($repository, 'code.c', "a\n");
        file_put_contents($repository->getWorkingDir().'/code.c', "b\n");

        $rawDiff = $repository->getWorkingCopy()->getDiff()->getRawDiff();

        $this->assertStringContainsString("diff --git a/code.c b/code.c\n", $rawDiff);
        $this->assertStringContainsString("--- a/code.c\n+++ b/code.c\n", $rawDiff);
    }

    protected function verifyCreateCommitDiff(Diff $diff): void
    {
        $files = $diff->getFiles();

        $this->assertCount(2, $files, '2 files in diff');

        $this->assertTrue($files[0]->isCreation(), 'script_A.php created');

        $this->assertNull($files[0]->getOldName(), 'First file name is a new file');
        $this->assertSame('script_A.php', $files[0]->getNewName(), 'First file name is script_A.php');
        $this->assertNull($files[0]->getOldMode(), 'First file mode is a new file');
        $this->assertSame('100644', $files[0]->getNewMode(), 'First file mode is correct');

        $this->assertSame(1, $files[0]->getAdditions(), '1 line added');
        $this->assertSame(0, $files[0]->getDeletions(), '0 lines deleted');
    }

    /**
     * @return array<array{string, array<array{int, int, int, int, array<array{string, string}>}>}> The name of each file, with its hunks
     */
    private static function changes(Diff $diff): array
    {
        return array_map(static fn (File $file): array => [$file->getName(), array_map(static fn (FileChange $change): array => [
            $change->getRangeOldStart(),
            $change->getRangeOldCount(),
            $change->getRangeNewStart(),
            $change->getRangeNewCount(),
            array_map(static fn (DiffLine $line): array => [$line->getType()->name, $line->getContent()], $change->getLines()),
        ], $file->getChanges())], $diff->getFiles());
    }

    private static function createWorkingCopy(): Repository
    {
        $repository = self::createEmptyRepository(false);
        self::git($repository, 'config', ['user.name', 'Unit Test']);
        self::git($repository, 'config', ['user.email', 'unit_test@unit-test.com']);
        // Whatever the default of the git running the tests.
        self::git($repository, 'symbolic-ref', ['HEAD', 'refs/heads/master']);

        return $repository;
    }

    private static function commitFile(Repository $repository, string $name, string $content): void
    {
        $path = $repository->getWorkingDir().'/'.$name;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o777, true);
        }
        file_put_contents($path, $content);
        self::git($repository, 'add', [$name]);
        self::git($repository, 'commit', ['-q', '-m', $name]);
    }
}
