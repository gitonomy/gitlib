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
use Gitonomy\Git\Exception\ProcessTimedOutException;
use Gitonomy\Git\Repository;
use Gitonomy\Git\Util\Credentials;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;

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
            $this->assertStringContainsString('http://***@127.0.0.1:1/repository.git', $e->getMessage());
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
        yield 'no scheme' => ['git@example.com:a.git', 'git@example.com:a.git'];
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
