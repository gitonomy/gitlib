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
use Gitonomy\Git\Util\Credentials;
use Gitonomy\Git\Util\RemoteUrl;
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
     * git talks through the file descriptors of an "fd::" URL, which it
     * inherits from PHP, like the socket of a database.
     */
    #[DataProvider('provideCalls')]
    public function testAnFdUrlIsRefused(\Closure $call): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"fd::0" uses the "fd::" transport, which talks through a file descriptor of PHP.');

        $call('fd::0', self::createTempDir().'/clone');
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

    /**
     * Before 2.14.1 (CVE-2017-1000117), git passes a host starting with a dash
     * to ssh, which reads it as an option: "-oProxyCommand=..." runs the
     * command. Recent versions of OpenSSH refuse such a host.
     */
    #[DataProvider('provideHostsStartingWithADash')]
    public function testAHostStartingWithADashIsRefused(\Closure $call, string $url): void
    {
        $dir = self::createTempDir();
        $previousCwd = getcwd();
        $this->assertIsString($previousCwd);
        chdir($dir);

        try {
            $call($url, $dir.'/clone');
            $this->fail('A host starting with a dash is refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(\sprintf('"%s" has a host or a user starting with a dash, which ssh, or the command of core.gitProxy, would read as an option.', Credentials::redact($url)), $e->getMessage());
        } finally {
            chdir($previousCwd);
        }

        $this->assertFileDoesNotExist($dir.'/pwned');
    }

    /**
     * @return iterable<string, array{\Closure(string, string): void, string}>
     */
    public static function provideHostsStartingWithADash(): iterable
    {
        // "${IFS}": the space of a command in a host.
        $command = '-oProxyCommand=touch${IFS}pwned';
        $urls = [
            'ssh' => 'ssh://'.$command.'/repository',
            'git+ssh' => 'git+ssh://'.$command.'/repository',
            'ssh+git' => 'ssh+git://'.$command.'/repository',
            'host between brackets' => 'ssh://['.$command.']/repository',
            'user' => 'ssh://'.$command.'@example.com/repository',
            'host after a user' => 'ssh://git@'.$command.'/repository',
            'address' => 'git@'.$command.':repository',
            'address between brackets' => '['.$command.']:repository',
            // Passed to the command of the core.gitProxy setting.
            'git' => 'git://'.$command.'/repository',
            // git decodes a URL before it reads its host.
            'encoded dash' => 'ssh://%2D'.substr($command, 1).'/repository',
            'encoded dash, lowercase' => 'git+ssh://%2d'.substr($command, 1).'/repository',
            'encoded dash of a user' => 'ssh://%2D'.substr($command, 1).'@example.com/repository',
            'encoded brackets' => 'ssh://%5B'.$command.'%5D/repository',
        ];

        foreach (self::provideCalls() as $callName => [$call]) {
            foreach ($urls as $urlName => $url) {
                yield $callName.', '.$urlName => [$call, $url];
            }
        }
    }

    #[DataProvider('provideUrlsGitReachesWithoutRunningACommand')]
    public function testAUrlGitReachesWithoutRunningACommandIsAccepted(string $url): void
    {
        RemoteUrl::assertSafe($url);

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUrlsGitReachesWithoutRunningACommand(): iterable
    {
        yield 'ssh' => ['ssh://git@example.com:2222/repository.git'];
        yield 'host between brackets' => ['ssh://git@[::1]/repository.git'];
        yield 'address' => ['git@example.com:repository.git'];
        yield 'dash in the path of an address' => ['git@example.com:-repository.git'];
        yield 'dash in the host' => ['ssh://git@my-host.example.com/repository.git'];
        yield 'encoded character in the user' => ['ssh://first%2Dlast@example.com/repository.git'];
        // Not reached through ssh: a token may start with a dash.
        yield 'https' => ['https://-token@example.com/repository.git'];
        yield 'local path' => ['/path/to/repository.git'];
        yield 'local path with a colon' => ['./a:b'];
    }
}
