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

use Gitonomy\Git\Blob;
use Gitonomy\Git\Commit;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;

class RepositoryTest extends AbstractTestCase
{
    public function testRunReturnsNullInsteadOfThrowingWhenDebugIsFalse(): void
    {
        $repository = self::createFoobarRepository(true);
        $repository = new Repository($repository->getPath(), array_merge(self::getOptions(), ['debug' => false]));

        $this->assertNull($repository->run('not-a-command'));
    }

    public function testGetShortHashThrowsCleanExceptionWhenDebugIsFalse(): void
    {
        $repository = self::createFoobarRepository(true);
        $repository = new Repository($repository->getPath(), array_merge(self::getOptions(), ['debug' => false]));

        $commit = new Commit($repository, str_repeat('a', 40));

        $this->expectException(ReferenceNotFoundException::class);

        $commit->getShortHash();
    }

    #[DataProvider('provideFoobar')]
    public function testGetBlobWithExistingWorks(Repository $repository): void
    {
        $blob = $repository->getCommit(self::LONGFILE_COMMIT)->getTree()->resolvePath('README.md');

        $this->assertInstanceOf(Blob::class, $blob, 'getBlob() returns a Blob object');
        $this->assertStringContainsString('Foo Bar project', $blob->getContent(), 'file is correct');
    }

    #[DataProvider('provideFoobar')]
    public function testGetSize(Repository $repository): void
    {
        $size = $repository->getSize();
        // The exact figure depends on git's packing/compression, which varies across versions.
        $this->assertGreaterThanOrEqual(40, $size, 'Repository is at least 40KB');
        $this->assertLessThan(84, $size, 'Repository is less than 84KB');
    }

    public function testIsBare(): void
    {
        $bare = self::createFoobarRepository(true);
        $this->assertTrue($bare->isBare(), 'Lib repository is bare');

        $notBare = self::createFoobarRepository(false);
        $this->assertFalse($notBare->isBare(), 'Working copy is not bare');
    }

    #[DataProvider('provideFoobar')]
    public function testGetDescription(Repository $repository): void
    {
        $this->assertSame("Unnamed repository; edit this file 'description' to name the repository.\n", $repository->getDescription());
    }

    #[DataProvider('provideFoobar')]
    public function testLoggerOk(Repository $repository): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('run command: remote "" ')
        ;
        $logger->expects($this->exactly(3)) // duration, return code and output
            ->method('debug')
            ->with($this->isString())
        ;

        $repository->setLogger($logger);

        $repository->run('remote');
    }

    #[DataProvider('provideFoobar')]
    public function testLoggerNOk(Repository $repository): void
    {
        $this->expectException(RuntimeException::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->isString())
        ;
        $logger->expects($this->exactly(3)) // duration, return code and output
            ->method('debug')
            ->with($this->isString())
        ;
        $logger->expects($this->once())
            ->method('error')
            ->with($this->isString())
        ;

        $repository->setLogger($logger);

        $repository->run('not-work');
    }

    /**
     * @see https://github.com/gitonomy/gitlib/issues/67
     */
    public function testRunResolvesRelativePathsAgainstTheRepositoryRegardlessOfCwd(): void
    {
        $repository = self::createFoobarRepository(false);

        $file = $repository->getWorkingDir().'/README.md';
        $original = file_get_contents($file);
        file_put_contents($file, $original."Applied line.\n");

        $patch = $repository->run('diff', ['--', 'README.md']);
        file_put_contents($file, $original);

        $patchFile = tempnam(sys_get_temp_dir(), 'gitlib_patch_');
        file_put_contents($patchFile, $patch);

        $previousCwd = getcwd();
        $this->assertIsString($previousCwd);
        chdir(sys_get_temp_dir());

        try {
            // "README.md" is relative to the repository work-tree, not to the
            // process cwd (which is an unrelated directory here). This only
            // works if the git process is run with its cwd set to the repository.
            $repository->run('apply', [$patchFile]);
        } finally {
            chdir($previousCwd);
            unlink($patchFile);
        }

        $this->assertSame($original."Applied line.\n", file_get_contents($file));
    }
}
