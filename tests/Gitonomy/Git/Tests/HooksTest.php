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

use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\Attributes\DataProvider;

class HooksTest extends AbstractTestCase
{
    private static ?bool $symlinkOnWindows = null;

    #[BeforeClass]
    public static function setUpWindows(): void
    {
        if (\defined('PHP_WINDOWS_VERSION_MAJOR')) {
            self::$symlinkOnWindows = true;
            $dir = self::createTempDir();
            touch($dir.'/origin');
            if (true !== @symlink($dir.'/origin', $dir.'/target')) {
                $report = error_get_last();
                if (\is_array($report) && str_contains($report['message'], 'error code(1314)')) {
                    self::$symlinkOnWindows = false;
                }
            }
        }
    }

    public function hookPath(Repository $repository, string $hook): string
    {
        return $repository->getGitDir().'/hooks/'.$hook;
    }

    public function touchHook(Repository $repository, string $hook, string $content = ''): string
    {
        $path = $this->hookPath($repository, $hook);
        file_put_contents($path, $content);

        return $path;
    }

    public function assertHasHook(Repository $repository, string $hook): void
    {
        $file = $this->hookPath($repository, $hook);

        $this->assertTrue($repository->getHooks()->has($hook), "hook {$hook} in repository");

        $this->assertFileExists($file, "Hook {$hook} is present");
    }

    public function assertNoHook(Repository $repository, string $hook): void
    {
        $file = $this->hookPath($repository, $hook);

        $this->assertFalse($repository->getHooks()->has($hook), "No hook {$hook} in repository");
        $this->assertFileDoesNotExist($file, "Hook {$hook} is not present");
    }

    #[DataProvider('provideBare')]
    public function testHas(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->assertNoHook($repository, 'foo');
        $this->touchHook($repository, 'foo');
        $this->assertHasHook($repository, 'foo');
    }

    #[DataProvider('provideBare')]
    public function testGetInvalidNameThrowsException(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->expectException(InvalidArgumentException::class);

        $repository->getHooks()->get('foo');
    }

    #[DataProvider('provideBare')]
    public function testGet(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->touchHook($repository, 'foo', 'foobar');

        $this->assertSame('foobar', $repository->getHooks()->get('foo'));
    }

    #[DataProvider('provideBare')]
    public function testSymlink(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->markAsSkippedIfSymlinkIsMissing();

        $file = $this->touchHook($repository, 'bar', 'barbarbar');
        $repository->getHooks()->setSymlink('foo', $file);

        $this->assertTrue(is_link($this->hookPath($repository, 'foo')), 'foo hook is a symlink');

        $this->assertSame(
            str_replace('\\', '/', $file),
            str_replace('\\', '/', (string) readlink($this->hookPath($repository, 'foo'))),
            'target of symlink is correct'
        );
    }

    #[DataProvider('provideBare')]
    public function testSymlinkWithExistingThrows(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->expectException(RuntimeException::class);

        $this->markAsSkippedIfSymlinkIsMissing();

        $file = $this->hookPath($repository, 'target-symlink');
        $fooFile = $this->hookPath($repository, 'foo');

        file_put_contents($file, 'foobar');
        touch($fooFile);

        $repository->getHooks()->setSymlink('foo', $file);
    }

    #[DataProvider('provideBare')]
    public function testSet(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $file = $this->hookPath($repository, 'foo');
        $repository->getHooks()->set('foo', 'bar');

        $this->assertSame('bar', file_get_contents($file), 'Hook content is correct');

        $perms = fileperms($file);
        $this->assertSame(\defined('PHP_WINDOWS_VERSION_BUILD') ? 0o666 : 0o700, $perms & 0o777, 'Hook permissions are correct');
    }

    #[DataProvider('provideBare')]
    public function testSetExistingThrows(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $repository->getHooks()->set('foo', 'bar');

        $this->expectException(RuntimeException::class);

        $repository->getHooks()->set('foo', 'bar');
    }

    #[DataProvider('provideBare')]
    public function testRemove(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $file = $this->hookPath($repository, 'foo');
        touch($file);

        $repository->getHooks()->remove('foo');

        $this->assertFileDoesNotExist($file);
    }

    #[DataProvider('provideBare')]
    public function testRemoveNotExistingThrows(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->expectException(RuntimeException::class);

        $repository->getHooks()->remove('foo');
    }

    public function testSetCreatesTheHooksDirectory(): void
    {
        $repository = self::createEmptyRepository();
        self::deleteDir($repository->getGitDir().'/hooks');

        $repository->getHooks()->set('pre-receive', "#!/bin/sh\nexit 1\n");

        $this->assertSame("#!/bin/sh\nexit 1\n", $repository->getHooks()->get('pre-receive'));
    }

    public function testALinkedWorktreeSharesTheHooksOfItsRepository(): void
    {
        $repository = self::createFoobarRepository(false);
        $dir = self::createTempDir().'/worktree';

        try {
            self::git($repository, 'worktree', ['add', '-b', 'in-worktree', $dir]);
        } catch (ProcessException) {
            $this->markTestSkipped('git worktree requires git 2.5.');
        }

        $worktree = new Repository($dir, driver: self::getDriver());
        $worktree->getHooks()->set('pre-commit', "#!/bin/sh\nexit 1\n");

        $this->assertHasHook($repository, 'pre-commit');
        $this->assertTrue($worktree->getHooks()->has('pre-commit'));
    }

    private function markAsSkippedIfSymlinkIsMissing(): void
    {
        if (!\function_exists('symlink')) {
            $this->markTestSkipped('symlink is not supported');
        }

        if (\defined('PHP_WINDOWS_VERSION_MAJOR') && false === self::$symlinkOnWindows) {
            $this->markTestSkipped('symlink requires "Create symbolic links" privilege on windows');
        }
    }
}
