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

use Gitonomy\Git\Driver\Cli\CliDriver;
use Gitonomy\Git\Driver\DriverInterface;
use Gitonomy\Git\Driver\Ffi\LibGit2Driver;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

abstract class AbstractTestCase extends TestCase
{
    /**
     * Local git bundle used as a fixture repository: cloning from it behaves like
     * cloning a remote repository, but requires no network access.
     */
    public const REPOSITORY_URL = __DIR__.'/../../../fixtures/foobar.bundle';

    public const NO_MESSAGE_COMMIT = '011cd0c1625190d2959ee9a8f9f822006d94b661';
    public const LONGFILE_COMMIT = '4f17752acc9b7c54ba679291bf24cb7d354f0f4f';
    public const BEFORE_LONGFILE_COMMIT = 'e0ec50e2af75fa35485513f60b2e658e245227e9';
    public const LONGMESSAGE_COMMIT = '3febd664b6886344a9b32d70657687ea4b1b4fab';
    public const INITIAL_COMMIT = '74acd054c8ec873ae6be044041d3a85a4f890ba5';
    public const MERGE_COMMIT = '2f5b9d0a4e6e7173d7816e417805709c708674f8';
    public const ENCODING_COMMIT = '779420b9b936f18a0b6579e1499a85b14270802e';
    public const SIGNED_COMMIT = 'e1a83f16ed61ae3807e5652c7ef894692c813513';

    /**
     * Local clone of remote URL. Avoids network call on each test.
     */
    private static ?Repository $localRepository = null;

    /**
     * Materialized (unbundled) copy of REPOSITORY_URL, used in place of the
     * raw .bundle file for drivers whose clone implementation doesn't
     * understand the git bundle format (unlike the `git` CLI, which
     * special-cases it) — currently LibGit2Driver.
     */
    private static ?string $materializedRepositoryUrl = null;

    /**
     * Creates an empty git repository and returns instance.
     */
    public static function createEmptyRepository(bool $bare = true): Repository
    {
        $dir = self::createTempDir();
        $repository = $bare ? Repository::initBare($dir, driver: self::getDriver()) : Repository::init($dir, driver: self::getDriver());
        self::registerDeletion($repository);

        return $repository;
    }

    /**
     * Can be used as data provider to get bare/not-bare repositories.
     */
    public static function provideFoobar(): array
    {
        return [
            [self::createFoobarRepository()],
            [self::createFoobarRepository(false)],
        ];
    }

    /**
     * Can be used as data provider to get bare/not-bare repositories.
     */
    public static function provideEmpty(): array
    {
        return [
            [self::createEmptyRepository()],
            [self::createEmptyRepository(false)],
        ];
    }

    /**
     * Creates a fixture test repository.
     */
    public static function createFoobarRepository(bool $bare = true): Repository
    {
        if (null === self::$localRepository) {
            self::$localRepository = self::cloneIntoTempDir(self::repositoryUrl(), $bare);
        }

        $repository = self::cloneIntoTempDir(self::$localRepository->getGitDir(), $bare);
        self::registerDeletion($repository);

        return $repository;
    }

    public static function registerDeletion(Repository $repository): void
    {
        register_shutdown_function(static function () use ($repository): void {
            if ($repository->getWorkingDir()) {
                $dir = $repository->getWorkingDir();
            } else {
                $dir = $repository->getGitDir();
            }
            self::deleteDir($dir);
        });
    }

    /**
     * Created an empty directory and return path to it.
     */
    public static function createTempDir(): string
    {
        $tmpDir = tempnam(sys_get_temp_dir(), 'gitlib_');
        unlink($tmpDir);
        mkdir($tmpDir);

        return $tmpDir;
    }

    /**
     * Returns the source to clone the fixture from: the raw .bundle file for
     * drivers that understand it (the `git` CLI does natively), or a
     * one-time unbundled bare repository otherwise.
     */
    protected static function repositoryUrl(): string
    {
        if (self::getDriver() instanceof CliDriver) {
            return self::REPOSITORY_URL;
        }

        if (null === self::$materializedRepositoryUrl) {
            $dir = self::createTempDir();
            $command = $_SERVER['GIT_COMMAND'] ?? 'git';
            $process = new Process([$command, 'clone', '-q', '--bare', self::REPOSITORY_URL, $dir]);
            $process->mustRun();
            self::$materializedRepositoryUrl = $dir;
        }

        return self::$materializedRepositoryUrl;
    }

    /**
     * Deletes a directory recursively.
     */
    protected static function deleteDir(string $dir): void
    {
        $iterator = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS);
        $iterator = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if (!is_link($file)) {
                chmod($file, 0o777);
            }
            if (is_dir($file)) {
                rmdir($file);
            } else {
                unlink($file);
            }
        }

        chmod($dir, 0o777);
        rmdir($dir);
    }

    /**
     * Skips the current test under LibGit2Driver: use at the top of tests
     * whose subject is the CLI-only Repository::run()/runProcess() escape
     * hatch, or the configuration of CliDriver. For mere setup, use git().
     */
    protected function skipUnlessCliDriver(): void
    {
        if (!self::getDriver() instanceof CliDriver) {
            self::markTestSkipped('This test is specific to CliDriver.');
        }
    }

    /**
     * Runs a git command on $repository through CliDriver, whatever the
     * driver under test: for setup (committing, staging, tagging...) that no
     * driver method covers.
     *
     * @param array<int|string, int|string> $args
     */
    protected static function git(Repository $repository, string $command, array $args = []): string
    {
        $cli = new Repository($repository->getPath(), driver: self::getCliDriver());

        return $cli->run($command, $args);
    }

    /**
     * Writes a loose object, without the checks recent git versions apply to
     * it (e.g. to a tag without tagger), nor the --literally option old ones lack.
     *
     * @return string The hash of the object
     */
    protected static function writeObject(Repository $repository, string $type, string $content): string
    {
        $object = $type.' '.\strlen($content)."\0".$content;
        $hash = sha1($object);

        $dir = $repository->getGitDir().'/objects/'.substr($hash, 0, 2);
        if (!is_dir($dir)) {
            mkdir($dir);
        }
        file_put_contents($dir.'/'.substr($hash, 2), gzcompress($object));

        return $hash;
    }

    /**
     * Returns the driver under test: LibGit2Driver when the GITLIB_DRIVER
     * environment variable is "ffi", CliDriver otherwise.
     */
    protected static function getDriver(): DriverInterface
    {
        return 'ffi' === ($_SERVER['GITLIB_DRIVER'] ?? 'cli') ? new LibGit2Driver() : self::getCliDriver();
    }

    /**
     * @param array<string, string> $environmentVariables Set on git processes, in addition to the GIT_ENVS environment variable
     */
    protected static function getCliDriver(array $environmentVariables = [], ?float $processTimeout = 60): CliDriver
    {
        $envs = isset($_SERVER['GIT_ENVS']) ? (array) $_SERVER['GIT_ENVS'] : [];

        return new CliDriver($_SERVER['GIT_COMMAND'] ?? 'git', [...$envs, ...$environmentVariables], processTimeout: $processTimeout);
    }

    /**
     * Clones $url into a new temporary directory, with the driver under test.
     */
    private static function cloneIntoTempDir(string $url, bool $bare): Repository
    {
        $dir = self::createTempDir();

        return $bare ? Repository::cloneBareFrom($url, $dir, driver: self::getDriver()) : Repository::cloneFrom($url, $dir, driver: self::getDriver());
    }
}
