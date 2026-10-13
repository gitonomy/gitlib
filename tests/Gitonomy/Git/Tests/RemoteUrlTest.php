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
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An application may check or clone a URL from its users: git must run no
 * command for it.
 */
class RemoteUrlTest extends AbstractTestCase
{
    /**
     * In a repository with a remote, "git ls-remote --upload-pack=..." runs
     * the command to list the references of the remote.
     */
    #[DataProvider('provideCalls')]
    public function testAUrlStartingWithADashIsRefused(\Closure $call): void
    {
        $repository = self::createFoobarRepository(false);
        $previousCwd = getcwd();
        $this->assertIsString($previousCwd);
        chdir($repository->getPath());

        try {
            // A colon before any slash: a URL, never a local path.
            $call('--upload-pack=touch pwned;:;git-upload-pack', self::createTempDir().'/clone');
            $this->fail('A leading dash is refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('starts with a dash', $e->getMessage());
        } finally {
            chdir($previousCwd);
        }

        $this->assertFileDoesNotExist($repository->getPath().'/pwned');
    }

    /**
     * git runs the command of an "ext::" URL, unless its protocol.allow
     * setting forbids it, as it does by default since git 2.12.
     */
    #[DataProvider('provideCalls')]
    public function testAnExtUrlIsRefused(\Closure $call): void
    {
        $file = self::createTempDir().'/pwned';

        try {
            // "% " is a space in the command of an "ext::" URL.
            $call('ext::sh -c touch% '.$file, self::createTempDir().'/clone');
            $this->fail('An "ext::" URL is refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('uses the "ext::" transport', $e->getMessage());
        }

        $this->assertFileDoesNotExist($file);
    }

    /**
     * @return iterable<string, array{\Closure(string, string): void}>
     */
    public static function provideCalls(): iterable
    {
        yield 'valid repository' => [static function (string $url): void { Repository::isValidRepository($url, self::getDriver()); }];
        yield 'valid branch' => [static function (string $url): void { Repository::isValidRepositoryAndBranch($url, 'master', self::getDriver()); }];
        yield 'clone' => [static function (string $url, string $path): void { Repository::cloneFrom($url, $path, driver: self::getDriver()); }];
        yield 'bare clone' => [static function (string $url, string $path): void { Repository::cloneBareFrom($url, $path, driver: self::getDriver()); }];
        yield 'mirror' => [static function (string $url, string $path): void { Repository::mirrorFrom($url, $path, self::getDriver()); }];
    }
}
