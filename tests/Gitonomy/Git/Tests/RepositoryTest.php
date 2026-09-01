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
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;

class RepositoryTest extends AbstractTestCase
{
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
}
