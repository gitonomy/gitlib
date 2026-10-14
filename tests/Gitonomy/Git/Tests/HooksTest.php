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

    /**
     * A hook is a file of the hooks directory: "../config" would be the
     * configuration of the repository.
     */
    #[DataProvider('provideInvalidNames')]
    public function testAnInvalidNameIsRefused(string $name): void
    {
        $repository = self::createFoobarRepository();
        $hooks = $repository->getHooks();
        $calls = [
            'has' => static fn () => $hooks->has($name),
            'get' => static fn () => $hooks->get($name),
            'set' => static fn () => $hooks->set($name, 'content'),
            'setSymlink' => static fn () => $hooks->setSymlink($name, __FILE__),
            'remove' => static fn () => $hooks->remove($name),
        ];

        foreach ($calls as $method => $call) {
            try {
                $call();
                $this->fail(\sprintf('%s() refuses "%s".', $method, $name));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('is not a valid hook name', $e->getMessage());
            }
        }

        $this->assertFileExists($repository->getGitDir().'/config');
        $this->assertFileDoesNotExist($repository->getGitDir().'/evil');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidNames(): iterable
    {
        yield 'parent directory' => ['../config'];
        yield 'new file in the parent directory' => ['../evil'];
        yield 'Windows separator' => ['..\config'];
        yield 'sub-directory' => ['sub/pre-commit'];
        yield 'dot' => ['.'];
        yield 'dot dot' => ['..'];
        yield 'empty' => [''];
        yield 'hidden file' => ['.pre-commit'];
        yield 'NUL byte' => ["pre-commit\0.sample"];
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
        $this->expectExceptionMessage('"../config" is not a valid hook name: it must be the name of a file of the hooks directory, like "pre-commit".');

        $repository->getHooks()->get('../config');
    }

    #[DataProvider('provideBare')]
    public function testGetMissingThrowsException(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The hook "foo" does not exist.');

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
        $this->expectExceptionMessage('The hook "foo" exists already.');

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
        $this->expectExceptionMessage('The hook "foo" exists already.');

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
        $this->expectExceptionMessage('The hook "foo" does not exist.');

        $repository->getHooks()->remove('foo');
    }

    /**
     * A symbolic link to a file deployed later: set() would write through it,
     * out of the hooks directory.
     */
    public function testASymlinkToAMissingFileIsAHook(): void
    {
        $this->markAsSkippedIfSymlinkIsMissing();

        $repository = self::createFoobarRepository();
        $target = self::createTempDir().'/pre-receive';
        $hooks = $repository->getHooks();
        $hooks->setSymlink('pre-receive', $target);

        $this->assertTrue($hooks->has('pre-receive'));

        try {
            $hooks->get('pre-receive');
            $this->fail('A symbolic link to a missing file can\'t be read.');
        } catch (RuntimeException $e) {
            $this->assertSame('Unable to read the hook "pre-receive".', $e->getMessage());
        }

        try {
            $hooks->set('pre-receive', "#!/bin/sh\nexit 1\n");
            $this->fail('The hook exists already.');
        } catch (RuntimeException $e) {
            $this->assertSame('The hook "pre-receive" exists already.', $e->getMessage());
        }
        $this->assertFileDoesNotExist($target, 'Nothing is written through the link');

        $hooks->remove('pre-receive');

        $this->assertFalse(is_link($this->hookPath($repository, 'pre-receive')));
        $this->assertFalse($hooks->has('pre-receive'));
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
        if (version_compare(self::gitVersion(), '2.5.0', '<')) {
            $this->markTestSkipped('git worktree requires git 2.5.');
        }

        $repository = self::createFoobarRepository(false);
        $dir = self::createTempDir().'/worktree';
        self::git($repository, 'worktree', ['add', '-b', 'in-worktree', $dir]);

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
