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
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Submodule;
use Gitonomy\Git\Tree;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

class TreeTest extends AbstractTestCase
{
    public const PATH_RESOLVING_COMMIT = 'cc06ac171d884282202dff88c1ded499a1f89420';

    #[DataProvider('provideBare')]
    public function testGetEntries(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $tree = $repository->getCommit(self::LONGFILE_COMMIT)->getTree();

        $entries = $tree->getEntries();

        $this->assertInstanceOf(Blob::class, $entries['long.php']->getObject());
        $this->assertSame('long.php', $entries['long.php']->getName());
        $this->assertSame('100644', $entries['long.php']->getMode());
        $this->assertInstanceOf(Blob::class, $entries['README.md']->getObject());
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
        file_put_contents($repository->getWorkingDir().'/back\slash'."\ttab\"quote", 'special');
        self::git($repository, 'add', ['--all']);
        self::git($repository, 'commit', ['-m', 'Add a file with special characters in its name']);

        $entries = $repository->getHeadCommit()->getTree()->getEntries();

        $this->assertArrayHasKey('back\slash'."\ttab\"quote", $entries);
    }

    #[DataProvider('provideBare')]
    public function testGetSubmoduleEntries(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $tree = $repository->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $submodules = $tree->getSubmoduleEntries();

        $this->assertSame(['barbaz'], array_keys($submodules));
        $this->assertInstanceOf(Submodule::class, $submodules['barbaz']->getObject());
        $this->assertSame('160000', $submodules['barbaz']->getMode());
    }

    #[DataProvider('provideBare')]
    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyGetCommitReferenceEntries(bool $bare): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Tree::getCommitReferenceEntries()" method is deprecated, use "getSubmoduleEntries()" instead.');
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: Reading a "Gitonomy\Git\TreeEntry" as an array is deprecated, use its "getMode()" and "getObject()" methods instead.');

        $tree = self::createFoobarRepository($bare)->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        [$mode, $object] = $tree->getCommitReferenceEntries()['barbaz'];

        $this->assertSame('160000', $mode);
        // @phpstan-ignore method.impossibleType (PHPStan doesn't follow the alias of the deprecated class.)
        $this->assertInstanceOf(CommitReference::class, $object);
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
        $entries = $resolved->getEntries();

        $this->assertArrayHasKey('d', $entries, 'Successfully resolved source folder');
    }
}
