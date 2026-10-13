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
 * libgit2 reads its arguments as C strings: "README.md\0.php" would name
 * "README.md", where git refuses it.
 */
class NulByteTest extends AbstractTestCase
{
    #[DataProvider('provideCalls')]
    public function testANulByteIsRefused(\Closure $call): void
    {
        $repository = self::createFoobarRepository();

        try {
            $call($repository);
            $this->fail('A NUL byte is refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('contains a NUL byte', $e->getMessage());
        }

        $this->assertTrue($repository->getReferences()->hasBranch('master'), 'Nothing is done');
    }

    /**
     * @return iterable<string, array{\Closure(Repository): void}>
     */
    public static function provideCalls(): iterable
    {
        yield 'revision' => [static function (Repository $repository): void { $repository->getRevision("master\0junk")->getCommit(); }];
        yield 'blame' => [static function (Repository $repository): void { $repository->getBlame('master', "README.md\0.php")->getLines(); }];
        yield 'log' => [static function (Repository $repository): void { $repository->getLog('master', "README.md\0.php")->getCommits(); }];
        yield 'branch creation' => [static function (Repository $repository): void { $repository->getReferences()->createBranch("new\0master", 'master'); }];
        yield 'branch deletion' => [static function (Repository $repository): void { new Branch($repository, "refs/heads/master\0junk", self::LONGFILE_COMMIT)->delete(); }];
    }

    public function testADirectoryWithANulByteIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contains a NUL byte');

        new Repository(self::createTempDir()."\0junk", driver: self::getDriver());
    }

    public function testAUrlWithANulByteIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contains a NUL byte');

        Repository::isValidRepository(self::repositoryUrl()."\0junk", self::getDriver());
    }
}
