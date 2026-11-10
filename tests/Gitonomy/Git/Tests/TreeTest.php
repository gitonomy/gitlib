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
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Submodule;
use Gitonomy\Git\Tree;
use PHPUnit\Framework\Attributes\DataProvider;

class TreeTest extends AbstractTestCase
{
    public const PATH_RESOLVING_COMMIT = 'cc06ac171d884282202dff88c1ded499a1f89420';

    #[DataProvider('provideBare')]
    public function testIterate(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $tree = $repository->getCommit(self::LONGFILE_COMMIT)->getTree();

        $entries = iterator_to_array($tree);

        $this->assertInstanceOf(Blob::class, $entries['long.php']->getObject());
        $this->assertSame('long.php', $entries['long.php']->getName());
        $this->assertSame('100644', $entries['long.php']->getMode());
        $this->assertInstanceOf(Blob::class, $entries['README.md']->getObject());
    }

    #[DataProvider('provideBare')]
    public function testIterateWithNonAsciiNames(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $entries = iterator_to_array($repository->getCommit(DiffTest::FILE_WITH_UMLAUTS_COMMIT)->getTree());

        $this->assertArrayHasKey('file_with_umlauts_äöü', $entries);
    }

    public function testIterateWithSpecialCharactersInNames(): void
    {
        if (\defined('PHP_WINDOWS_VERSION_BUILD')) {
            $this->markTestSkipped('Windows has no file name with a backslash, a tab or a quote.');
        }

        $repository = self::createFoobarRepository(false);
        self::git($repository, 'config', ['user.name', 'Unit Test']);
        self::git($repository, 'config', ['user.email', 'unit_test@unit-test.com']);
        file_put_contents($repository->getWorkingDir().'/back\slash'."\ttab\"quote", 'special');
        self::git($repository, 'add', ['--all']);
        self::git($repository, 'commit', ['-m', 'Add a file with special characters in its name']);

        $entries = iterator_to_array($repository->getHeadCommit()->getTree());

        $this->assertArrayHasKey('back\slash'."\ttab\"quote", $entries);
    }

    #[DataProvider('provideBare')]
    public function testGetSubmodules(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $tree = $repository->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $submodules = $tree->getSubmodules();

        $this->assertSame(['barbaz'], array_keys($submodules));
        $this->assertInstanceOf(Submodule::class, $submodules['barbaz']->getObject());
        $this->assertSame('160000', $submodules['barbaz']->getMode());
    }

    #[DataProvider('provideBare')]
    public function testASubmoduleHasTheHashOfItsCommit(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $submodule = $repository->getCommit(self::NO_MESSAGE_COMMIT)->getTree()->getSubmodules()['barbaz']->getObject();

        $this->assertInstanceOf(Submodule::class, $submodule);
        $this->assertSame('5a519c47166f333e32091e166eb55f729106c964', $submodule->getHash());
    }

    #[DataProvider('provideBare')]
    public function testGetEntry(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $tree = $repository->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $blob = $tree->getEntry('README.md');
        $this->assertInstanceOf(Blob::class, $blob);
        $this->assertSame('07eeea94584fb6f1d8fe55e5b78adc4668c02508', $blob->getHash());
        $this->assertInstanceOf(Submodule::class, $tree->getEntry('barbaz'));
        $this->assertInstanceOf(Tree::class, $repository->getCommit(self::PATH_RESOLVING_COMMIT)->getTree()->getEntry('test'));
    }

    public function testGetEntryOfAMissingNameThrows(): void
    {
        $tree = self::createFoobarRepository()->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The tree "c4491f20ce7f24f0b5a344c823afb0dff58449fb" has no entry "nope".');

        $tree->getEntry('nope');
    }

    public function testResolvePathThroughASubmoduleThrows(): void
    {
        $tree = self::createFoobarRepository()->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to resolve "barbaz/file": "barbaz" is a submodule, not a tree.');

        $tree->resolvePath('barbaz/file');
    }

    public function testResolveAMissingPathThrows(): void
    {
        $tree = self::createFoobarRepository()->getCommit(self::PATH_RESOLVING_COMMIT)->getTree();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to resolve "test/a/missing/c": "test/a/missing" doesn\'t exist.');

        $tree->resolvePath('test/a/missing/c');
    }

    public function testResolveTheRootPath(): void
    {
        $tree = self::createFoobarRepository()->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $this->assertSame($tree, $tree->resolvePath('/'));
        $this->assertSame($tree, $tree->resolvePath(''));
    }

    #[DataProvider('provideBare')]
    public function testGetTrees(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);

        $this->assertSame([], $repository->getCommit(self::NO_MESSAGE_COMMIT)->getTree()->getTrees());

        $trees = $repository->getCommit(self::PATH_RESOLVING_COMMIT)->getTree()->getTrees();

        $this->assertSame(['test'], array_keys($trees));
        $this->assertInstanceOf(Tree::class, $trees['test']->getObject());
        $this->assertSame('040000', $trees['test']->getMode());
    }

    #[DataProvider('provideBare')]
    public function testGetBlobs(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $tree = $repository->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $blobs = $tree->getBlobs();

        $this->assertArrayNotHasKey('barbaz', $blobs);
        $this->assertInstanceOf(Blob::class, $blobs['README.md']->getObject());
    }

    #[DataProvider('provideBare')]
    public function testResolvePath(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $tree = $repository->getCommit(self::PATH_RESOLVING_COMMIT)->getTree();
        $path = 'test/a/b/c';

        $resolved = $tree->resolvePath($path);
        $this->assertInstanceOf(Tree::class, $resolved);
        $entries = iterator_to_array($resolved);

        $this->assertArrayHasKey('d', $entries, 'Successfully resolved source folder');
    }

    /**
     * Old versions of git wrote other modes than 100644 and 100755 for a
     * file: "git ls-tree", since 2.17, and libgit2 read them as these two.
     */
    public function testTheModeOfAFileOfAnOldTreeIsReadLikeGit(): void
    {
        $repository = self::createEmptyRepository();
        $blob = self::writeObject($repository, 'blob', "content\n");
        $tree = self::writeObject($repository, 'tree', "100664 file\0".hex2bin($blob)."100775 script\0".hex2bin($blob));

        $modes = [];
        foreach ($repository->getTree($tree) as $name => $entry) {
            $modes[$name] = $entry->getMode();
        }

        $this->assertSame(['file' => '100644', 'script' => '100755'], $modes);
    }
}
