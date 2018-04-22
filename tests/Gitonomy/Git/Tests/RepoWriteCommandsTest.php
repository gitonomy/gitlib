<?php

/**
 * This file is part of Gitonomy.
 *
 * (c) David Pacheco <david.pacheco@beubi.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace Gitonomy\Git\Tests;

class RepoWriteCommandsTest extends AbstractTest
{
    /**
     * @expectedException        Gitonomy\Git\Exception\ProcessException
     * @expectedExceptionMessage fatal: pathspec 'test/a/b/c' did not match any files
     */
    public function testAddNonExistingFile()
    {
        $repository = self::createFoobarRepository(false);
        $path = 'test/a/b/c';

        $repository->addFile($path);
    }

    public function testAddExistingFile()
    {
        $repository = self::createFoobarRepository(false);
        $path = 'test.sh';

        $output = $repository->addFile($path);

        $this->assertEquals('', $output);
    }

    public function testCleanRepo()
    {
        $repository = self::createFoobarRepository(false);

        $output = $repository->cleanRepo(array('-f'));

        $this->assertEquals('', $output);
    }

    /**
     * @expectedException        Gitonomy\Git\Exception\ProcessException
     * @expectedExceptionMessage fatal: clean.requireForce defaults to true
     */
    public function testCleanRepoFail()
    {
        $repository = self::createFoobarRepository(false);

        $output = $repository->cleanRepo(array(''));

        $this->assertEquals('', $output);
    }

    public function testResetRepo()
    {
        $repository = self::createFoobarRepository(false);

        $output = $repository->resetRepo(array('--hard'));

        $this->assertContains('HEAD is now at', $output);
    }

    /**
     * @expectedException        Gitonomy\Git\Exception\ProcessException
     * @expectedExceptionMessage nothing to commit, working directory clean
     */
    public function testCommitToRepo()
    {
        $repository = self::createFoobarRepository(false);

        $repository->commitChanges('test', 'test.user@gmail.com', 'Test User');
    }

    /**
     * @expectedException        Gitonomy\Git\Exception\ProcessException
     * @expectedExceptionMessage error: src refspec test does not match any.
     */
    public function testPushToRepo()
    {
        $repository = self::createFoobarRepository(false);

        $repository->pushToRemote('test');
    }
}
