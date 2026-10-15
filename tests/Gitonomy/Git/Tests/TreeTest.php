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
use Gitonomy\Git\CommitReference;
use Gitonomy\Git\Submodule;
use Gitonomy\Git\Tree;
use Gitonomy\Git\TreeEntry;
use Symfony\Bridge\PhpUnit\ExpectDeprecationTrait;

class TreeTest extends AbstractTest
{
    use ExpectDeprecationTrait;

    const PATH_RESOLVING_COMMIT = 'cc06ac171d884282202dff88c1ded499a1f89420';

    /**
     * @dataProvider provideFooBar
     */
    public function testIterate($repository)
    {
        $tree = $repository->getCommit(self::LONGFILE_COMMIT)->getTree();

        $entries = iterator_to_array($tree);

        $this->assertInstanceOf(TreeEntry::class, $entries['long.php']);
        $this->assertSame('long.php', $entries['long.php']->getName());
        $this->assertSame('100644', $entries['long.php']->getMode());
        $this->assertInstanceOf(Blob::class, $entries['long.php']->getObject());
        $this->assertInstanceOf(Blob::class, $entries['README.md']->getObject());
    }

    /**
     * @dataProvider provideFooBar
     */
    public function testGetSubmodules($repository)
    {
        $tree = $repository->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $submodules = $tree->getSubmodules();

        $this->assertSame(['barbaz'], array_keys($submodules));
        $this->assertSame('barbaz', $submodules['barbaz']->getName());
        $this->assertSame('160000', $submodules['barbaz']->getMode());
        $this->assertInstanceOf(Submodule::class, $submodules['barbaz']->getObject());
        // A CommitReference, like in 1.6, which 2.0 makes an alias of Submodule.
        $this->assertSame(CommitReference::class, get_class($submodules['barbaz']->getObject()));
        $this->assertSame('5a519c47166f333e32091e166eb55f729106c964', $submodules['barbaz']->getObject()->getHash());
    }

    /**
     * @dataProvider provideFooBar
     */
    public function testGetTrees($repository)
    {
        $this->assertSame([], $repository->getCommit(self::NO_MESSAGE_COMMIT)->getTree()->getTrees());

        $trees = $repository->getCommit(self::PATH_RESOLVING_COMMIT)->getTree()->getTrees();

        $this->assertSame('040000', $trees['test']->getMode());
        $this->assertInstanceOf(Tree::class, $trees['test']->getObject());
    }

    /**
     * @dataProvider provideFooBar
     */
    public function testGetBlobs($repository)
    {
        $tree = $repository->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $blobs = $tree->getBlobs();

        $this->assertSame('README.md', $blobs['README.md']->getName());
        $this->assertInstanceOf(Blob::class, $blobs['README.md']->getObject());
        $this->assertArrayNotHasKey('barbaz', $blobs);
    }

    /**
     * @dataProvider provideFooBar
     */
    public function testResolvePath($repository)
    {
        $tree = $repository->getCommit(self::PATH_RESOLVING_COMMIT)->getTree();
        $path = 'test/a/b/c';

        $resolved = $tree->resolvePath($path);

        $this->assertArrayHasKey('d', iterator_to_array($resolved), 'Successfully resolved source folder');
    }

    /**
     * @dataProvider provideFooBar
     *
     * @group legacy
     */
    public function testGetEntries($repository)
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Tree::getEntries()" method is deprecated, iterate the tree instead.');

        $tree = $repository->getCommit(self::LONGFILE_COMMIT)->getTree();

        $entries = $tree->getEntries();

        $this->assertSame('100644', $entries['long.php'][0]);
        $this->assertInstanceOf(Blob::class, $entries['long.php'][1]);
        $this->assertInstanceOf(Blob::class, $entries['README.md'][1]);
    }

    /**
     * @dataProvider provideFooBar
     *
     * @group legacy
     */
    public function testGetCommitReferenceEntries($repository)
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Tree::getCommitReferenceEntries()" method is deprecated, use "getSubmodules()" instead.');

        $tree = $repository->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $commits = $tree->getCommitReferenceEntries();

        $this->assertSame('160000', $commits['barbaz'][0]);
        $this->assertInstanceOf(CommitReference::class, $commits['barbaz'][1]);
    }

    /**
     * @dataProvider provideFooBar
     *
     * @group legacy
     */
    public function testGetTreeEntries($repository)
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Tree::getTreeEntries()" method is deprecated, use "getTrees()" instead.');

        $trees = $repository->getCommit(self::PATH_RESOLVING_COMMIT)->getTree()->getTreeEntries();

        $this->assertSame('040000', $trees['test'][0]);
        $this->assertInstanceOf(Tree::class, $trees['test'][1]);
    }

    /**
     * @dataProvider provideFooBar
     *
     * @group legacy
     */
    public function testGetBlobEntries($repository)
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Tree::getBlobEntries()" method is deprecated, use "getBlobs()" instead.');

        $tree = $repository->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $blobs = $tree->getBlobEntries();

        $this->assertInstanceOf(Blob::class, $blobs['README.md'][1]);
    }
}
