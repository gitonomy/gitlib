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
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * git reads an argument starting with a dash as an option: a revision from
 * the URL of a web application, like "--output=/path", would write a file.
 */
class LeadingDashTest extends AbstractTestCase
{
    #[DataProvider('provideCalls')]
    public function testARevisionStartingWithADashIsRefused(\Closure $call): void
    {
        $repository = self::createFoobarRepository(false);
        $file = self::createTempDir().'/written';

        try {
            $call($repository, '--output='.$file);
            $this->fail('A leading dash is refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('starts with a dash', $e->getMessage());
        }

        $this->assertFileDoesNotExist($file);
        $this->assertTrue($repository->getReferences()->hasBranch('master'), 'Nothing is done');
    }

    /**
     * @return iterable<string, array{\Closure(Repository, string): void}>
     */
    public static function provideCalls(): iterable
    {
        yield 'revision' => [static function (Repository $repository, string $option): void { $repository->getRevision($option)->getCommit(); }];
        yield 'range of a log' => [static function (Repository $repository, string $option): void { $repository->getLog($option.'..master')->getCommits(); }];
        yield 'blame' => [static function (Repository $repository, string $option): void { $repository->getBlame($option, 'README.md')->getLines(); }];
        yield 'diff' => [static function (Repository $repository, string $option): void { $repository->getDiff($option, 'master'); }];
        yield 'checkout' => [static function (Repository $repository, string $option): void { $repository->getWorkingCopy()->checkout($option); }];
        yield 'new branch' => [static function (Repository $repository, string $option): void { $repository->getWorkingCopy()->checkoutNewBranch($option, 'master'); }];
        yield 'merge' => [static function (Repository $repository, string $option): void { $repository->getWorkingCopy()->merge($option); }];
        yield 'reference' => [static function (Repository $repository, string $option): void { new Branch($repository, $option, self::LONGFILE_COMMIT)->delete(); }];
    }

    /**
     * In a repository with a remote, "git ls-remote --upload-pack=..." runs
     * the command to list the references of the remote.
     */
    #[DataProvider('provideUrlCalls')]
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
     * @return iterable<string, array{\Closure(string, string): void}>
     */
    public static function provideUrlCalls(): iterable
    {
        yield 'valid repository' => [static function (string $url): void { Repository::isValidRepository($url, self::getDriver()); }];
        yield 'valid branch' => [static function (string $url): void { Repository::isValidRepositoryAndBranch($url, 'master', self::getDriver()); }];
        yield 'clone' => [static function (string $url, string $path): void { Repository::cloneFrom($url, $path, driver: self::getDriver()); }];
        yield 'bare clone' => [static function (string $url, string $path): void { Repository::cloneBareFrom($url, $path, driver: self::getDriver()); }];
        yield 'mirror' => [static function (string $url, string $path): void { Repository::mirrorFrom($url, $path, self::getDriver()); }];
    }

    /**
     * Unlike a URL, a path may start with a dash: git reads it after "--".
     */
    public function testAPathMayStartWithADash(): void
    {
        $dir = self::createTempDir();
        $previousCwd = getcwd();
        $this->assertIsString($previousCwd);
        chdir($dir);

        try {
            Repository::init('-repository', self::getDriver());
            Repository::initBare('-bare', self::getDriver());
            Repository::cloneFrom(self::repositoryUrl(), '-clone', driver: self::getDriver());
        } finally {
            chdir($previousCwd);
        }

        $this->assertDirectoryExists($dir.'/-repository/.git');
        $this->assertFileExists($dir.'/-bare/HEAD');
        $this->assertFileExists($dir.'/-clone/README.md');
    }
}
