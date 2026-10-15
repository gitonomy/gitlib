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
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\DataProvider;

class BlobTest extends AbstractTestCase
{
    public const README_FRAGMENT = 'Foo Bar project';

    #[DataProvider('provideBare')]
    public function testGetContent(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $blob = self::getReadmeBlob($repository);

        $this->assertStringContainsString(self::README_FRAGMENT, $blob->getContent());
    }

    #[DataProvider('provideBare')]
    public function testGetContentSiblingToSubmodule(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        // Regression test for https://github.com/gitonomy/gitlib/issues/12:
        // a submodule entry in a tree used to break parsing of that tree,
        // making sibling blobs unreadable.
        $tree = $repository->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $this->assertNotEmpty($tree->getSubmodules(), 'Tree contains a submodule entry');

        $blob = $tree->resolvePath('README.md');

        $this->assertInstanceOf(Blob::class, $blob);
        $this->assertStringContainsString(self::README_FRAGMENT, $blob->getContent());
    }

    #[DataProvider('provideBare')]
    public function testNotExisting(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $blob = $repository->getBlob(str_repeat('1', 40));

        $this->expectException(ReferenceNotFoundException::class);
        $this->expectExceptionMessage('The object "1111111111111111111111111111111111111111" does not exist.');

        $blob->getContent();
    }

    public function testNotAHash(): void
    {
        $this->expectException(ReferenceNotFoundException::class);
        $this->expectExceptionMessage('"master" is not a hash.');

        self::createFoobarRepository()->getBlob('master');
    }

    #[DataProvider('provideBare')]
    public function testGetMimetype(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $blob = self::getReadmeBlob($repository);

        $this->assertSame('text/plain', $blob->getMimetype());
    }

    #[DataProvider('provideBare')]
    public function testIsText(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $readmeBlob = self::getReadmeBlob($repository);
        $this->assertTrue($readmeBlob->isText());
        $imageBlob = self::getImageBlob($repository);
        $this->assertFalse($imageBlob->isText());
    }

    #[DataProvider('provideBare')]
    public function testIsBinary(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $readmeBlob = self::getReadmeBlob($repository);
        $this->assertFalse($readmeBlob->isBinary());
        $imageBlob = self::getImageBlob($repository);
        $this->assertTrue($imageBlob->isBinary());
    }

    #[DataProvider('provideBare')]
    public function testAbbreviatedHash(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $hash = '412a0dad051163dcf9f9ce2fc04a141ffcb69479';

        $this->assertSame($repository->getBlob($hash)->getContent(), $repository->getBlob(substr($hash, 0, 8))->getContent());
    }

    private static function getReadmeBlob(Repository $repository): Blob
    {
        return self::blob($repository, 'README.md');
    }

    private static function getImageBlob(Repository $repository): Blob
    {
        return self::blob($repository, 'image.jpg');
    }

    private static function blob(Repository $repository, string $path): Blob
    {
        $blob = $repository->getCommit(self::LONGFILE_COMMIT)->getTree()->resolvePath($path);
        self::assertInstanceOf(Blob::class, $blob);

        return $blob;
    }
}
