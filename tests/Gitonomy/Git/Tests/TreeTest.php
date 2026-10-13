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
use Gitonomy\Git\CommitReference;
use PHPUnit\Framework\Attributes\DataProvider;

class TreeTest extends AbstractTestCase
{
    public const PATH_RESOLVING_COMMIT = 'cc06ac171d884282202dff88c1ded499a1f89420';

    #[DataProvider('provideBare')]
    public function testGetEntries(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $tree = $repository->getCommit(self::LONGFILE_COMMIT)->getTree();

        $entries = $tree->getEntries();

        $this->assertNotEmpty($entries['long.php'], 'long.php is present');
        $this->assertTrue($entries['long.php'][1] instanceof Blob, 'long.php is a Blob');

        $this->assertNotEmpty($entries['README.md'], 'README.md is present');
        $this->assertTrue($entries['README.md'][1] instanceof Blob, 'README.md is a Blob');
    }

    #[DataProvider('provideBare')]
    public function testGetEntriesWithNonAsciiNames(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $entries = $repository->getCommit(DiffTest::FILE_WITH_UMLAUTS_COMMIT)->getTree()->getEntries();

        $this->assertArrayHasKey('file_with_umlauts_äöü', $entries);
    }

    public function testGetEntriesWithSpecialCharactersInNames(): void
    {
        $repository = self::createFoobarRepository(false);
        self::git($repository, 'config', ['user.name', 'Unit Test']);
        self::git($repository, 'config', ['user.email', 'unit_test@unit-test.com']);
        file_put_contents($repository->getWorkingDir().'/tab\t"quote"', 'special');
        self::git($repository, 'add', ['--all']);
        self::git($repository, 'commit', ['-m', 'Add a file with special characters in its name']);

        $entries = $repository->getHeadCommit()->getTree()->getEntries();

        $this->assertArrayHasKey('tab\t"quote"', $entries);
    }

    #[DataProvider('provideBare')]
    public function testGetCommitReferenceEntries(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $tree = $repository->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $commits = $tree->getCommitReferenceEntries();

        $this->assertNotEmpty($commits['barbaz'], 'barbaz is present');
        $this->assertTrue($commits['barbaz'][1] instanceof CommitReference, 'barbaz is a Commit');
    }

    #[DataProvider('provideBare')]
    public function testGetTreeEntries(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $tree = $repository->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $trees = $tree->getTreeEntries();

        $this->assertEmpty($trees);
    }

    #[DataProvider('provideBare')]
    public function testGetBlobEntries(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $tree = $repository->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $blobs = $tree->getBlobEntries();

        $this->assertNotEmpty($blobs['README.md'], 'README.md is present');
        $this->assertTrue($blobs['README.md'][1] instanceof Blob, 'README.md is a blob');
    }

    #[DataProvider('provideBare')]
    public function testResolvePath(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $tree = $repository->getCommit(self::PATH_RESOLVING_COMMIT)->getTree();
        $path = 'test/a/b/c';

        $resolved = $tree->resolvePath($path);
        $entries = $resolved->getEntries();

        $this->assertNotEmpty($entries['d'], 'Successfully resolved source folder');
    }
}
