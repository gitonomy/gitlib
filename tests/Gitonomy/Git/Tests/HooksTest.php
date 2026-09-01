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

use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\LogicException;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\Attributes\DataProvider;

class HooksTest extends AbstractTestCase
{
    private static ?bool $symlinkOnWindows = null;

    #[BeforeClass]
    public static function setUpWindows(): void
    {
        if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
            self::$symlinkOnWindows = true;
            $originDir = tempnam(sys_get_temp_dir(), 'sl');
            $targetDir = tempnam(sys_get_temp_dir(), 'sl');
            if (true !== @symlink($originDir, $targetDir)) {
                $report = error_get_last();
                if (is_array($report) && false !== strpos($report['message'], 'error code(1314)')) {
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

        $this->assertTrue($repository->getHooks()->has($hook), "hook $hook in repository");

        $this->assertFileExists($file, "Hook $hook is present");
    }

    public function assertNoHook(Repository $repository, string $hook): void
    {
        $file = $this->hookPath($repository, $hook);

        $this->assertFalse($repository->getHooks()->has($hook), "No hook $hook in repository");
        $this->assertFileDoesNotExist($file, "Hook $hook is not present");
    }

    #[DataProvider('provideFoobar')]
    public function testHas(Repository $repository): void
    {
        $this->assertNoHook($repository, 'foo');
        $this->touchHook($repository, 'foo');
        $this->assertHasHook($repository, 'foo');
    }

    #[DataProvider('provideFoobar')]
    public function testGet_InvalidName_ThrowsException(Repository $repository): void
    {
        $this->expectException(InvalidArgumentException::class);

        $repository->getHooks()->get('foo');
    }

    #[DataProvider('provideFoobar')]
    public function testGet(Repository $repository): void
    {
        $this->touchHook($repository, 'foo', 'foobar');

        $this->assertEquals('foobar', $repository->getHooks()->get('foo'));
    }

    #[DataProvider('provideFoobar')]
    public function testSymlink(Repository $repository): void
    {
        $this->markAsSkippedIfSymlinkIsMissing();

        $file = $this->touchHook($repository, 'bar', 'barbarbar');
        $repository->getHooks()->setSymlink('foo', $file);

        $this->assertTrue(is_link($this->hookPath($repository, 'foo')), 'foo hook is a symlink');

        $this->assertEquals(
            str_replace('\\', '/', $file),
            str_replace('\\', '/', readlink($this->hookPath($repository, 'foo'))),
            'target of symlink is correct'
        );
    }

    #[DataProvider('provideFoobar')]
    public function testSymlink_WithExisting_ThrowsLogicException(Repository $repository): void
    {
        $this->expectException(LogicException::class);

        $this->markAsSkippedIfSymlinkIsMissing();

        $file = $this->hookPath($repository, 'target-symlink');
        $fooFile = $this->hookPath($repository, 'foo');

        file_put_contents($file, 'foobar');
        touch($fooFile);

        $repository->getHooks()->setSymlink('foo', $file);
    }

    #[DataProvider('provideFoobar')]
    public function testSet(Repository $repository): void
    {
        $file = $this->hookPath($repository, 'foo');
        $repository->getHooks()->set('foo', 'bar');

        $this->assertEquals('bar', file_get_contents($file), 'Hook content is correct');

        $perms = fileperms($file);
        $this->assertEquals(defined('PHP_WINDOWS_VERSION_BUILD') ? 0666 : 0777, $perms & 0777, 'Hook permissions are correct');
    }

    #[DataProvider('provideFoobar')]
    public function testSet_Existing_ThrowsLogicException(Repository $repository): void
    {
        $repository->getHooks()->set('foo', 'bar');

        $this->expectException(LogicException::class);

        $repository->getHooks()->set('foo', 'bar');
    }

    #[DataProvider('provideFoobar')]
    public function testRemove(Repository $repository): void
    {
        $file = $this->hookPath($repository, 'foo');
        touch($file);

        $repository->getHooks()->remove('foo');

        $this->assertFileDoesNotExist($file);
    }

    #[DataProvider('provideFoobar')]
    public function testRemove_NotExisting_ThrowsLogicException(Repository $repository): void
    {
        $this->expectException(LogicException::class);

        $repository->getHooks()->remove('foo');
    }

    private function markAsSkippedIfSymlinkIsMissing(): void
    {
        if (!function_exists('symlink')) {
            $this->markTestSkipped('symlink is not supported');
        }

        if (defined('PHP_WINDOWS_VERSION_MAJOR') && false === self::$symlinkOnWindows) {
            $this->markTestSkipped('symlink requires "Create symbolic links" privilege on windows');
        }
    }
}
