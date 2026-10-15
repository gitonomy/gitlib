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
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

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

    /**
     * In a process of its own: once Submodule is loaded, it declares the alias,
     * whose file isn't loaded anymore.
     */
    #[Group('legacy')]
    #[IgnoreDeprecations]
    #[RunInSeparateProcess]
    public function testLegacyCommitReference(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\CommitReference" class is deprecated, use "Gitonomy\Git\Submodule" instead.');

        $this->assertTrue(class_exists(CommitReference::class));
    }

    #[DataProvider('provideBare')]
    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyGetCommitReferenceEntries(bool $bare): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Tree::getCommitReferenceEntries()" method is deprecated, use "getSubmodules()" instead.');

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

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyGetEntries(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Tree::getEntries()" method is deprecated, iterate the tree instead.');

        $tree = self::createFoobarRepository()->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $entries = $tree->getEntries();

        $this->assertSame(array_keys(iterator_to_array($tree)), array_keys($entries));
        $this->assertSame(['100644', $tree->getEntry('README.md')], $entries['README.md']);
        $this->assertSame(['160000', $tree->getEntry('barbaz')], $entries['barbaz']);
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyGetBlobEntries(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Tree::getBlobEntries()" method is deprecated, use "getBlobs()" instead.');

        $tree = self::createFoobarRepository()->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $blobs = $tree->getBlobEntries();

        $this->assertSame(array_keys($tree->getBlobs()), array_keys($blobs));
        $this->assertSame(['100644', $tree->getEntry('README.md')], $blobs['README.md']);
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyGetTreeEntries(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Tree::getTreeEntries()" method is deprecated, use "getTrees()" instead.');

        $tree = self::createFoobarRepository()->getCommit(self::PATH_RESOLVING_COMMIT)->getTree();

        $this->assertSame(['test' => ['040000', $tree->getEntry('test')]], $tree->getTreeEntries());
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
}
