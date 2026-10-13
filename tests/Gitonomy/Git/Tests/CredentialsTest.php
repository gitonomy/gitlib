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
            $this->assertStringNotContainsString('secret', $e->getMessage());
            $this->assertStringContainsString('http://***@127.0.0.1:1/repository.git', $e->getMessage());
        }
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
        yield 'several URLs' => ['ssh://***@a.com/x and https://***@b.com/y', 'ssh://git@a.com/x and https://user:password@b.com/y'];
        yield 'no user information' => ['https://example.com/a@b.git', 'https://example.com/a@b.git'];
        yield 'no scheme' => ['git@example.com:a.git', 'git@example.com:a.git'];
    }
}
