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
use Gitonomy\Git\Diff\File;
use Gitonomy\Git\Repository;

class DiffTest extends AbstractTest
{
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
        $this->assertFalse($files[0]->isChangeMode());
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testGetFiles_Changemode($repository)
    {
        $files = $repository->getCommit(self::CHANGEMODE_COMMIT)->getDiff()->getFiles();

        $this->assertCount(1, $files, '1 files modified');

        $this->assertTrue($files[0]->isModification());
        $this->assertTrue($files[0]->isChangeMode());
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

    public function testDeleteFileWithoutRaw()
    {
        $deprecationCalled = false;
        $self = $this;
        set_error_handler(function (int $errno, string $errstr) use ($self, &$deprecationCalled): void {
            $deprecationCalled = true;
            $self->assertSame('Using Diff::parse without raw information is deprecated. See https://github.com/gitonomy/gitlib/issues/227.', $errstr);
        }, E_USER_DEPRECATED);

        $diff = Diff::parse(<<<'DIFF'
        diff --git a/test b/test
        deleted file mode 100644
        index e69de29bb2d1d6434b8b29ae775ad8c2e48c5391..0000000000000000000000000000000000000000

        DIFF);
        $firstFile = $diff->getFiles()[0];

        restore_exception_handler();

        $this->assertTrue($deprecationCalled);
        $this->assertFalse($firstFile->isCreation());
        $this->assertTrue($firstFile->isDeletion());
        $this->assertFalse($firstFile->isChangeMode());
        $this->assertSame('e69de29bb2d1d6434b8b29ae775ad8c2e48c5391', $firstFile->getOldIndex());
        $this->assertNull($firstFile->getNewIndex());
    }

    public function testModeChangeFileWithoutRaw()
    {
        $deprecationCalled = false;
        $self = $this;
        set_error_handler(function (int $errno, string $errstr) use ($self, &$deprecationCalled): void {
            $deprecationCalled = true;
            $self->assertSame('Using Diff::parse without raw information is deprecated. See https://github.com/gitonomy/gitlib/issues/227.', $errstr);
        }, E_USER_DEPRECATED);

        $diff = Diff::parse(<<<'DIFF'
        diff --git a/a.out b/a.out
        old mode 100755
        new mode 100644

        DIFF);
        $firstFile = $diff->getFiles()[0];

        restore_exception_handler();

        $this->assertTrue($deprecationCalled);
        $this->assertFalse($firstFile->isCreation());
        $this->assertFalse($firstFile->isDeletion());
        $this->assertTrue($firstFile->isChangeMode());
        $this->assertSame('', $firstFile->getOldIndex());
        $this->assertSame('', $firstFile->getNewIndex());
    }

    public function testModeChangeFileWithRaw()
    {
        $deprecationCalled = false;
        set_error_handler(function (int $errno, string $errstr) use (&$deprecationCalled): void {
            $deprecationCalled = true;
        }, E_USER_DEPRECATED);

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

        restore_exception_handler();

        $this->assertFalse($deprecationCalled);

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

    public function testThrowErrorOnBlobGetWithoutIndex()
    {
        $repository = $this->getMockBuilder(Repository::class)->disableOriginalConstructor()->getMock();
        $file = new File('oldName', 'newName', '100755', '100644', '', '', false);
        $file->setRepository($repository);

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
        $this->assertTrue($file->isChangeMode());
        $this->assertSame('', $file->getOldIndex());
        $this->assertSame('', $file->getNewIndex());
    }

    public function testEmptyNewFile()
    {
        $diff = Diff::parse("diff --git a/test b/test\nnew file mode 100644\nindex 0000000000000000000000000000000000000000..e69de29bb2d1d6434b8b29ae775ad8c2e48c5391\n");
        $firstFile = $diff->getFiles()[0];

        $this->assertTrue($firstFile->isCreation());
        $this->assertFalse($firstFile->isDeletion());
        $this->assertSame('test', $firstFile->getNewName());
        $this->assertNull($firstFile->getOldName());
    }

    public function testEmptyOldFile()
    {
        $diff = Diff::parse("diff --git a/test b/test\ndeleted file mode 100644\nindex e69de29bb2d1d6434b8b29ae775ad8c2e48c5391..0000000000000000000000000000000000000000\n");
        $firstFile = $diff->getFiles()[0];

        $this->assertFalse($firstFile->isCreation());
        $this->assertTrue($firstFile->isDeletion());
        $this->assertNull($firstFile->getNewName());
        $this->assertSame('test', $firstFile->getOldName());
    }
}
