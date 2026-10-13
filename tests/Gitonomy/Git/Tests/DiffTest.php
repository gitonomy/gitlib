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
use Gitonomy\Git\Diff\File;
use Gitonomy\Git\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

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

        $this->assertEquals('image.jpg', $files[0]->getOldName(), 'Second file name is image.jpg');
        $this->assertEquals('image.jpg', $files[0]->getNewName(), 'Second file name is image.jpg');
        $this->assertEquals('100644', $files[0]->getOldMode(), 'Second file mode is a new file');
        $this->assertEquals('100644', $files[0]->getNewMode(), 'Second file mode is correct');

        $this->assertTrue($files[0]->isBinary(), 'binary file');
        $this->assertEquals(0, $files[0]->getAdditions(), '0 lines added');
        $this->assertEquals(0, $files[0]->getDeletions(), '0 lines deleted');
    }

    #[DataProvider('provideBare')]
    public function testGetFilesDeletion(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $files = $repository->getCommit(self::DELETE_COMMIT)->getDiff()->getFiles();

        $this->assertCount(1, $files, '1 files modified');

        $this->assertTrue($files[0]->isDeletion(), 'File deletion');
        $this->assertEquals('script_B.php', $files[0]->getOldName(), 'verify old filename');
        $this->assertEquals(1, $files[0]->getDeletions(), '1 line deleted');
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
        $this->assertFalse($files[0]->isChangeMode());
    }

    #[DataProvider('provideBare')]
    public function testGetFilesChangemode(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $files = $repository->getCommit(self::CHANGEMODE_COMMIT)->getDiff()->getFiles();

        $this->assertCount(1, $files, '1 files modified');

        $this->assertTrue($files[0]->isModification());
        $this->assertTrue($files[0]->isChangeMode());
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
        $this->assertFalse($firstFile->isChangeMode());
        $this->assertSame('e69de29bb2d1d6434b8b29ae775ad8c2e48c5391', $firstFile->getOldIndex());
        $this->assertNull($firstFile->getNewIndex());
    }

    public function testModeChangeFile(): void
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
        $this->assertTrue($firstFile->isChangeMode());
        $this->assertSame('d1af4b23d0cc9313e5b2d3ef2fb9696c94afaa81', $firstFile->getOldIndex());
        $this->assertSame('d1af4b23d0cc9313e5b2d3ef2fb9696c94afaa81', $firstFile->getNewIndex());

        $this->assertFalse($secondFile->isCreation());
        $this->assertFalse($secondFile->isDeletion());
        $this->assertTrue($secondFile->isChangeMode());
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

    public function testParseAnEmptyDiff(): void
    {
        $this->assertSame([], Diff::parse('')->getFiles());
    }

    public function testThrowErrorOnBlobGetWithoutIndex(): void
    {
        $repository = self::createEmptyRepository();
        $file = new File('oldName', 'newName', '100755', '100644', '', '', false);
        $file->setRepository($repository);

        try {
            $file->getOldBlob();
        } catch (\RuntimeException $exception) {
            $this->assertSame('Index is missing to return Blob object.', $exception->getMessage());
        }

        try {
            $file->getNewBlob();
        } catch (\RuntimeException $exception) {
            $this->assertSame('Index is missing to return Blob object.', $exception->getMessage());
        }

        $this->assertFalse($file->isCreation());
        $this->assertFalse($file->isDeletion());
        $this->assertTrue($file->isChangeMode());
        $this->assertSame('', $file->getOldIndex());
        $this->assertSame('', $file->getNewIndex());
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

    protected function verifyCreateCommitDiff(Diff $diff): void
    {
        $files = $diff->getFiles();

        $this->assertCount(2, $files, '1 file in diff');

        $this->assertTrue($files[0]->isCreation(), 'script_A.php created');

        $this->assertNull($files[0]->getOldName(), 'First file name is a new file');
        $this->assertEquals('script_A.php', $files[0]->getNewName(), 'First file name is script_A.php');
        $this->assertNull($files[0]->getOldMode(), 'First file mode is a new file');
        $this->assertEquals('100644', $files[0]->getNewMode(), 'First file mode is correct');

        $this->assertEquals(1, $files[0]->getAdditions(), '1 line added');
        $this->assertEquals(0, $files[0]->getDeletions(), '0 lines deleted');
    }
}
