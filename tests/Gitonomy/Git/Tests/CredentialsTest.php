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
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\GitExceptionInterface;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\ProcessTimedOutException;
use Gitonomy\Git\Repository;
use Gitonomy\Git\Util\Credentials;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * The credentials of a URL must not end up in the logs or in an error tracker.
 */
class CredentialsTest extends AbstractTestCase
{
    /**
     * Nothing listens on port 1: the clone fails.
     */
    private const string URL = 'http://user:secret@127.0.0.1:1/repository.git';

    public function testAFailedCloneHidesTheCredentials(): void
    {
        try {
            Repository::cloneFrom(self::URL, self::createTempDir().'/clone', driver: self::getDriver());
            $this->fail('The clone fails.');
        } catch (DriverException $e) {
            // What loggers print: the message, the trace, and the chained exceptions.
            $this->assertStringNotContainsString('secret', (string) $e);
            if (self::getDriver() instanceof CliDriver) {
                // The error of git: with http support, it names the URL without its credentials. Without,
                // it does not name it, and git 2.34 prints no "fatal: ..." but "git: 'remote-http' is not
                // a git command."
                $this->assertStringStartsWith('The git command "clone" failed (exit 128): ', $e->getMessage());
                $this->assertStringNotContainsString('user', $e->getMessage());
            } else {
                $this->assertStringStartsWith('Unable to clone "http://***@127.0.0.1:1/repository.git": ', $e->getMessage());
            }
        }
    }

    /**
     * git writes the name of a branch it refuses in its error output.
     */
    public function testTheMessageOfAProcessExceptionHidesTheCredentials(): void
    {
        $this->skipUnlessCliDriver();

        try {
            self::createFoobarRepository()->run('branch', [self::URL]);
            $this->fail('A URL is no valid branch name.');
        } catch (ProcessException $e) {
            $this->assertStringStartsWith('The git command "branch" failed (exit 128): fatal: \'http://***@127.0.0.1:1/repository.git\' is not a valid branch name', $e->getMessage());
            $this->assertStringNotContainsString('secret', (string) $e);
            $this->assertStringContainsString(self::URL, $e->getErrorOutput(), 'The error output is the one of git');
        }
    }

    /**
     * The exception of Symfony Process holds the command line.
     */
    public function testATimeoutHidesTheCredentials(): void
    {
        $this->skipUnlessCliDriver();
        if (\defined('PHP_WINDOWS_VERSION_BUILD')) {
            $this->markTestSkipped('The slow git is a shell script.');
        }

        $slowGit = self::createTempDir().'/git';
        file_put_contents($slowGit, "#!/bin/sh\nexec sleep 10\n");
        chmod($slowGit, 0o700);

        try {
            Repository::cloneFrom(self::URL, self::createTempDir().'/clone', driver: new CliDriver($slowGit, self::gitEnvs(), processTimeout: 0.2));
            $this->fail('The clone times out.');
        } catch (ProcessTimedOutException $e) {
            $this->assertSame('The git command "clone" ran longer than 0.2 seconds.', $e->getMessage());
            $this->assertStringNotContainsString('secret', (string) $e);
        }
    }

    /**
     * The exception of Symfony Process holds the command line: here, longer
     * than what the system accepts.
     */
    public function testAProcessThatCannotStartHidesTheCredentials(): void
    {
        $this->skipUnlessCliDriver();

        try {
            Repository::isValidRepository(self::URL.'/'.str_repeat('a', 4 * 1024 * 1024), self::getDriver());
            $this->fail('The command line is too long.');
        } catch (DriverException $e) {
            // One line, the error of the system, like "posix_spawn() failed: Argument list too long", without the command line.
            $this->assertMatchesRegularExpression('/^The git command "ls-remote" could not start: [^\n]+\.$/', $e->getMessage());
            $this->assertStringNotContainsString('/repository.git/aaa', $e->getMessage());
            $this->assertStringNotContainsString('secret', (string) $e);
        }
    }

    /**
     * The exceptions of the arguments gitlib refuses hide the credentials too.
     */
    #[DataProvider('provideRefusedUrls')]
    public function testARefusedUrlHidesTheCredentials(string $url, string $message): void
    {
        try {
            Repository::isValidRepository($url, self::getDriver());
            $this->fail('The URL is refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRefusedUrls(): iterable
    {
        yield 'NUL byte' => [self::URL."\0", '"http://***@127.0.0.1:1/repository.git\000" contains a NUL byte, which git doesn\'t accept.'];
        yield 'leading dash' => ['--upload-pack='.self::URL, '"--upload-pack=http://***@127.0.0.1:1/repository.git" starts with a dash, which git would read as an option.'];
        yield 'ext' => ['ext::sh -c cat% '.self::URL, '"ext::sh -c cat% http://***@127.0.0.1:1/repository.git" uses the "ext::" transport, which runs a command.'];
        yield 'fd' => ['fd::0/'.self::URL, '"fd::0/http://***@127.0.0.1:1/repository.git" uses the "fd::" transport, which talks through a file descriptor of PHP.'];
    }

    /**
     * With "zend.exception_ignore_args" off, the default of php.ini-development,
     * the traces of exceptions hold the arguments of the calls.
     */
    #[DataProvider('provideFailingCalls')]
    public function testTheTracesHideTheCredentials(\Closure $call): void
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

        try {
            $call(self::getDriver());
            $this->fail('The call fails.');
        } catch (GitExceptionInterface $e) {
            for ($exception = $e; null !== $exception; $exception = $exception->getPrevious()) {
                foreach ($exception->getTrace() as $frame) {
                    $this->assertNoSecretIn($frame['args'] ?? []);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        }
    }

    /**
     * @return iterable<string, array{\Closure(DriverInterface): void}>
     */
    public static function provideFailingCalls(): iterable
    {
        yield 'clone' => [static function (DriverInterface $driver): void { Repository::cloneFrom(self::URL, self::createTempDir().'/clone', driver: $driver); }];
        yield 'bare clone' => [static function (DriverInterface $driver): void { Repository::cloneBareFrom(self::URL, self::createTempDir().'/clone', driver: $driver); }];
        yield 'mirror' => [static function (DriverInterface $driver): void { Repository::mirrorFrom(self::URL, self::createTempDir().'/clone', $driver); }];
        yield 'NUL byte' => [static function (DriverInterface $driver): void { Repository::isValidRepository(self::URL."\0", $driver); }];
        yield 'NUL byte in a branch' => [static function (DriverInterface $driver): void { Repository::isValidRepositoryAndBranch(self::URL, "master\0", $driver); }];
        yield 'ext' => [static function (DriverInterface $driver): void { Repository::cloneFrom('ext::'.self::URL, self::createTempDir().'/clone', driver: $driver); }];
        yield 'run' => [static function (DriverInterface $driver): void { new Repository(self::createFoobarRepository()->getPath(), driver: $driver)->run('remote', ['add', 'private', self::URL."\0"]); }];
        yield 'leading dash' => [static function (DriverInterface $driver): void { Repository::isValidRepository('--upload-pack='.self::URL, $driver); }];
    }

    public function testTheLogsHideTheCredentials(): void
    {
        $this->skipUnlessCliDriver();

        $messages = [];
        $log = static function (string $message) use (&$messages): void {
            $messages[] = $message;
        };
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback($log);
        $logger->method('debug')->willReturnCallback($log);

        $repository = new Repository(self::createFoobarRepository()->getPath(), driver: self::getCliDriver(logger: $logger));
        // The URL is in the command, then in its output.
        $repository->run('remote', ['add', 'private', self::URL]);
        $repository->run('remote', ['-v']);

        $this->assertStringContainsString("private\thttp://***@127.0.0.1:1/repository.git (fetch)\n", implode("\n", $messages));
        foreach ($messages as $message) {
            $this->assertStringNotContainsString('secret', $message);
        }
    }

    #[DataProvider('provideTexts')]
    public function testRedact(string $expected, string $text): void
    {
        $this->assertSame($expected, Credentials::redact($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideTexts(): iterable
    {
        yield 'user and password' => ["clone 'https://***@example.com/a.git'", "clone 'https://user:password@example.com/a.git'"];
        yield 'token' => ['https://***@example.com', 'https://ghp_token@example.com'];
        yield 'password holding an "@"' => ['https://***@example.com/a.git', 'https://user:p@ss@example.com/a.git'];
        yield 'password holding a "#"' => ['https://***@example.com/a.git', 'https://user:p#ss@example.com/a.git'];
        yield 'password holding a "?"' => ['https://***@example.com/a.git', 'https://user:p?ss@example.com/a.git'];
        yield 'several URLs' => ['ssh://***@a.com/x and https://***@b.com/y', 'ssh://git@a.com/x and https://user:password@b.com/y'];
        yield 'no user information' => ['https://example.com/a@b.git', 'https://example.com/a@b.git'];
        yield 'no user information, with a port' => ['https://example.com:8080/a@b.git', 'https://example.com:8080/a@b.git'];
        yield 'no scheme' => ['git@example.com:a.git', 'git@example.com:a.git'];
        yield 'no scheme, the error of ssh' => ['git@example.com: Permission denied (publickey).', 'git@example.com: Permission denied (publickey).'];
        yield 'no scheme, an address after a label' => ['Author: jane@example.com', 'Author: jane@example.com'];
        yield 'no scheme, the host git prints for a git:// URL' => ['fatal: unable to look up ***@example.com (port 9418)', 'fatal: unable to look up user:password@example.com (port 9418)'];
        yield 'no scheme, with a port' => ['unable to look up ***@example.com:9418', 'unable to look up user:password@example.com:9418'];
        yield 'no scheme, password holding an "@"' => ['unable to look up ***@example.com', 'unable to look up user:p@ss@example.com'];
        yield 'no scheme, the error of ssh with a password' => ['***@example.com: Permission denied (publickey).', 'user:password@example.com: Permission denied (publickey).'];
        yield 'no scheme, a revision git quotes' => ["fatal: bad revision 'HEAD:logo@2x.png'", "fatal: bad revision 'HEAD:logo@2x.png'"];
        yield 'no scheme, an address git quotes' => ["fatal: 'example.com:a@b.git' does not appear to be a git repository", "fatal: 'example.com:a@b.git' does not appear to be a git repository"];
        yield 'no scheme, a reference of the reflog' => ['error: src refspec main:refs/heads/x@{1} does not match any', 'error: src refspec main:refs/heads/x@{1} does not match any'];
        yield 'no scheme, not followed by a host' => ['std::move:x@(y)', 'std::move:x@(y)'];
        yield 'any scheme' => ['_https://***@example.com/a.git', '_https://user:password@example.com/a.git'];
    }

    /**
     * With a logger, the output of every command is redacted: megabytes, for
     * a blob. The regular expression reached the limits of PCRE on them, and
     * threw a TypeError.
     */
    #[DataProvider('provideLongTexts')]
    public function testRedactTakesALongText(string $expected, string $text): void
    {
        $this->assertSame($expected, Credentials::redact($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLongTexts(): iterable
    {
        $text = 'x:'.str_repeat('a@', 1_100_000);
        yield 'no host after the last "@"' => [$text, $text];
        yield 'a password of "@"' => ['https://***@example.com/a.git', 'https://x:'.str_repeat('a@', 1_100_000).'example.com/a.git'];
        $text = str_repeat("a.b-c+d e:f g@h\n", 100_000);
        yield 'no credentials' => [$text, $text];
    }

    /**
     * The logs hold the error output of git, which names the branch it refuses.
     */
    public function testTheLogsHideTheCredentialsOfAnErrorOutput(): void
    {
        $this->skipUnlessCliDriver();

        $messages = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('debug')->willReturnCallback(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $repository = new Repository(self::createFoobarRepository()->getPath(), driver: self::getCliDriver(logger: $logger));

        try {
            $repository->run('branch', [self::URL]);
            $this->fail('A URL is no valid branch name.');
        } catch (ProcessException) {
        }

        $this->assertStringContainsString("last command (branch) error output: fatal: 'http://***@127.0.0.1:1/repository.git' is not a valid branch name", implode("\n", $messages));
        foreach ($messages as $message) {
            $this->assertStringNotContainsString('secret', $message);
        }
    }

    /**
     * Like the shell that can't run git, a wrapper of git may exit with 127,
     * after an error naming its arguments.
     */
    public function testAGitThatCannotRunHidesTheCredentials(): void
    {
        $this->skipUnlessCliDriver();
        if (\defined('PHP_WINDOWS_VERSION_BUILD')) {
            $this->markTestSkipped('The wrapper of git is a shell script.');
        }

        $wrapper = self::createTempDir().'/git';
        file_put_contents($wrapper, "#!/bin/sh\necho \"wrapper: unable to run git \$*\" >&2\nexit 127\n");
        chmod($wrapper, 0o700);
        $path = self::createTempDir().'/clone';

        try {
            Repository::cloneFrom(self::URL, $path, driver: new CliDriver($wrapper, self::gitEnvs()));
            $this->fail('git does not run.');
        } catch (DriverException $e) {
            $this->assertSame(\sprintf('The git command "clone" could not start: wrapper: unable to run git clone -q -- http://***@127.0.0.1:1/repository.git %s.', $path), $e->getMessage());
            $this->assertStringNotContainsString('secret', (string) $e);
        }
    }

    /**
     * The exception of Symfony Process isn't chained either when git is killed.
     */
    public function testAKilledGitHidesTheCredentials(): void
    {
        $this->skipUnlessCliDriver();
        if (\defined('PHP_WINDOWS_VERSION_BUILD')) {
            $this->markTestSkipped('The git killing itself is a shell script.');
        }

        $killedGit = self::createTempDir().'/git';
        file_put_contents($killedGit, "#!/bin/sh\nkill -9 \$\$\n");
        chmod($killedGit, 0o700);

        try {
            Repository::cloneFrom(self::URL, self::createTempDir().'/clone', driver: new CliDriver($killedGit, self::gitEnvs()));
            $this->fail('git is killed.');
        } catch (DriverException $e) {
            $this->assertSame('Unable to run the git command "clone": The process has been signaled with signal "9".', $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('secret', (string) $e);
        }
    }

    /**
     * LibGit2Driver checks the directory itself, and names the URL like libgit2 does.
     */
    public function testACloneIntoADirectoryWithFilesHidesTheCredentials(): void
    {
        $dir = self::createTempDir();
        touch($dir.'/file');

        try {
            Repository::cloneBareFrom(self::URL, $dir, driver: self::getDriver());
            $this->fail('The directory has files.');
        } catch (DriverException $e) {
            $this->assertSame(self::getDriver() instanceof CliDriver
                ? \sprintf('The git command "clone" failed (exit 128): fatal: destination path \'%s\' already exists and is not an empty directory.', $dir)
                : \sprintf('Unable to clone "http://***@127.0.0.1:1/repository.git": "%s" exists and is not an empty directory.', $dir), $e->getMessage());
            $this->assertStringNotContainsString('secret', (string) $e);
        }
    }

    /**
     * LibGit2Driver names the URL that has no such branch. No other test
     * reaches a server: "git daemon" serves the repository here.
     */
    public function testACloneOfAMissingBranchHidesTheCredentials(): void
    {
        if (self::getDriver() instanceof CliDriver) {
            $this->markTestSkipped('git reads no user in a git:// URL: it looks "user:secret@127.0.0.1" up as a host.');
        }

        $dir = self::createTempDir();
        Repository::cloneBareFrom(self::repositoryUrl(), $dir.'/repository.git', driver: self::getDriver());

        $socket = stream_socket_server('tcp://127.0.0.1:0') ?: throw new \RuntimeException('Unable to find a free port.');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $url = \sprintf('git://user:secret@127.0.0.1:%d/repository.git', $port);

        $daemon = new Process([self::gitCommand(), 'daemon', '--base-path='.$dir, '--export-all', '--listen=127.0.0.1', '--port='.$port, '--reuseaddr', $dir], env: self::gitEnvs());
        $daemon->start();

        try {
            for ($attempts = 0; !Repository::isValidRepository($url, self::getDriver()); ++$attempts) {
                if (50 === $attempts || !$daemon->isRunning()) {
                    $this->markTestSkipped('"git daemon" does not start: '.$daemon->getErrorOutput());
                }
                usleep(100_000);
            }

            try {
                Repository::cloneBareFrom($url, $dir.'/clone', 'missing', self::getDriver());
                $this->fail('The branch is missing.');
            } catch (DriverException $e) {
                $this->assertSame(\sprintf('Remote branch "missing" not found in "git://***@127.0.0.1:%d/repository.git".', $port), $e->getMessage());
                $this->assertStringNotContainsString('secret', (string) $e);
            }
        } finally {
            $daemon->stop(1);
        }
    }

    /**
     * The trace of an exception holds the arguments of every call on the way:
     * the methods a URL goes through hide it, even those no test makes fail.
     */
    #[DataProvider('provideParametersHoldingAUrl')]
    public function testAParameterHoldingAUrlIsSensitive(\ReflectionParameter $parameter): void
    {
        $this->assertCount(1, $parameter->getAttributes(\SensitiveParameter::class));
    }

    /**
     * @return iterable<string, array{\ReflectionParameter}>
     */
    public static function provideParametersHoldingAUrl(): iterable
    {
        $src = \dirname(__DIR__, 4).'/src/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src.'Gitonomy/Git', \FilesystemIterator::SKIP_DOTS));

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $class = strtr(substr($file->getPathname(), \strlen($src), -\strlen('.php')), '/', '\\');
            // Not an interface, which is in no trace, nor libgit2.h.
            if (!class_exists($class)) {
                continue;
            }

            foreach (new \ReflectionClass($class)->getMethods() as $method) {
                foreach ($method->getParameters() as $parameter) {
                    // $config: the configuration of a clone, with the URL of its remote.
                    if (\in_array($parameter->getName(), ['config', 'url'], true)) {
                        yield \sprintf('%s::%s(), $%s', $class, $method->getName(), $parameter->getName()) => [$parameter];
                    }
                }
            }
        }
    }

    /**
     * @param array<mixed> $values
     */
    private function assertNoSecretIn(array $values): void
    {
        foreach ($values as $value) {
            if (\is_array($value)) {
                $this->assertNoSecretIn($value);
            } elseif (\is_string($value)) {
                $this->assertStringNotContainsString('secret', $value);
            }
        }
    }
}
