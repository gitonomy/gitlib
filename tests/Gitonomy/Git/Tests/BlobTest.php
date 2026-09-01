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

use Gitonomy\Git\Blob;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\DataProvider;

class BlobTest extends AbstractTestCase
{
    const README_FRAGMENT = 'Foo Bar project';

    public function getReadmeBlob(Repository $repository): Blob
    {
        return $repository->getCommit(self::LONGFILE_COMMIT)->getTree()->resolvePath('README.md');
    }

    public function getImageBlob(Repository $repository): Blob
    {
        return $repository->getCommit(self::LONGFILE_COMMIT)->getTree()->resolvePath('image.jpg');
    }

    #[DataProvider('provideFoobar')]
    public function testGetContent(Repository $repository): void
    {
        $blob = $this->getReadmeBlob($repository);

        $this->assertStringContainsString(self::README_FRAGMENT, $blob->getContent());
    }

    #[DataProvider('provideFoobar')]
    public function testNotExisting(Repository $repository): void
    {
        $this->expectException(RuntimeException::class);

        $blob = $repository->getBlob('foobar');
        $blob->getContent();
    }

    #[DataProvider('provideFoobar')]
    public function testGetMimetype(Repository $repository): void
    {
        $blob = $this->getReadmeBlob($repository);

        $this->assertMatchesRegularExpression('#text/plain#', $blob->getMimetype());
    }

    #[DataProvider('provideFoobar')]
    public function testIsText(Repository $repository): void
    {
        $readmeBlob = $this->getReadmeBlob($repository);
        $this->assertTrue($readmeBlob->isText());
        $imageBlob = $this->getImageBlob($repository);
        $this->assertFalse($imageBlob->isText());
    }

    #[DataProvider('provideFoobar')]
    public function testIsBinary(Repository $repository): void
    {
        $readmeBlob = $this->getReadmeBlob($repository);
        $this->assertFalse($readmeBlob->isBinary());
        $imageBlob = $this->getImageBlob($repository);
        $this->assertTrue($imageBlob->isBinary());
    }
}
