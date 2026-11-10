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

use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Repository;
use Gitonomy\Git\Util\Credentials;
use Gitonomy\Git\Util\RemoteUrl;
use PHPUnit\Framework\Attributes\After;
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
        $canary = self::createCanary();
        $previousCwd = getcwd();
        $this->assertIsString($previousCwd);
        chdir($repository->getPath());

        $exception = null;

        try {
            // A colon before any slash: a URL, never a local path.
            $call('--upload-pack=touch "$GITLIB_CANARY";:;git-upload-pack', self::createTempDir().'/clone');
        } catch (InvalidArgumentException|DriverException $exception) {
            // A DriverException: the failure of git, when the URL isn't refused.
        } finally {
            chdir($previousCwd);
        }

        // Checked first: a URL that isn't refused may not run its command either.
        $this->assertFileDoesNotExist($canary);
        $this->assertInstanceOf(InvalidArgumentException::class, $exception, 'A leading dash is refused.');
        $this->assertStringContainsString('starts with a dash', $exception->getMessage());
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
     * command. Recent versions of OpenSSH refuse such a host. The command of
     * core.gitProxy gets the port of a git URL the same way.
     */
    #[DataProvider('provideHostsStartingWithADash')]
    public function testAHostStartingWithADashIsRefused(\Closure $call, string $url): void
    {
        $canary = self::createCanary();

        $exception = null;

        try {
            $call($url, self::createTempDir().'/clone');
        } catch (InvalidArgumentException|DriverException $exception) {
            // A DriverException: the failure of git, or of ssh, when the host isn't refused.
        }

        // Checked first: a URL that isn't refused may not run its command either.
        $this->assertFileDoesNotExist($canary);
        $this->assertInstanceOf(InvalidArgumentException::class, $exception, 'A host starting with a dash is refused.');
        $this->assertSame(\sprintf('"%s" has a host, a user or a port starting with a dash, which ssh, or the command of core.gitProxy, would read as an option.', Credentials::redact($url)), $exception->getMessage());
    }

    /**
     * @return iterable<string, array{\Closure(string, string): void, string}>
     */
    public static function provideHostsStartingWithADash(): iterable
    {
        // "${IFS}": the space of a command in a host.
        $command = '-oProxyCommand=touch${IFS}$GITLIB_CANARY';
        $urls = [
            'ssh' => 'ssh://'.$command.'/repository',
            'git+ssh' => 'git+ssh://'.$command.'/repository',
            'ssh+git' => 'ssh+git://'.$command.'/repository',
            'host between brackets' => 'ssh://['.$command.']/repository',
            'user' => 'ssh://'.$command.'@example.com/repository',
            'host after a user' => 'ssh://git@'.$command.'/repository',
            'address' => 'git@'.$command.':repository',
            'address between brackets' => '['.$command.']:repository',
            // Passed to the command of the core.gitProxy setting, like the port: before 2.14.1, whatever follows the colon.
            'git' => 'git://'.$command.'/repository',
            'port' => 'git://example.com:'.$command.'/repository',
            'port after a host between brackets' => 'git://[::1]:'.$command.'/repository',
            'encoded dash of a port' => 'git://example.com:%2D'.substr($command, 1).'/repository',
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
        yield 'host between brackets, with a port' => ['ssh://git@[::1]:2222/repository.git'];
        yield 'git, with a port' => ['git://example.com:9418/repository.git'];
        yield 'address' => ['git@example.com:repository.git'];
        // git passes it to ssh in the command to run on the host, between quotes.
        yield 'dash in the path of an address' => ['git@example.com:-repository.git'];
        yield 'address between brackets, with a port' => ['[git@example.com:2222]:repository.git'];
        yield 'dash in the host' => ['ssh://git@my-host.example.com/repository.git'];
        yield 'encoded character in the user' => ['ssh://first%2Dlast@example.com/repository.git'];
        // Not reached through ssh: a token may start with a dash.
        yield 'https' => ['https://-token@example.com/repository.git'];
        yield 'local path' => ['/path/to/repository.git'];
        yield 'local path with a colon' => ['./a:b'];
    }

    #[After]
    public function removeCanary(): void
    {
        putenv('GITLIB_CANARY');
        unset($_SERVER['GITLIB_CANARY']);
    }

    /**
     * The commands of the URLs create the file the GITLIB_CANARY environment
     * variable names, which git and ssh inherit: wherever a driver runs git,
     * and without a slash in the URL, which would make a path of it.
     *
     * @return string The file, which doesn't exist
     */
    private static function createCanary(): string
    {
        $file = self::createTempDir().'/pwned';
        // Symfony Process passes on the variables of getenv() that $_SERVER has too.
        putenv('GITLIB_CANARY='.$file);
        $_SERVER['GITLIB_CANARY'] = $file;

        return $file;
    }
}
