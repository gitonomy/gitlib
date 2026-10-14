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

use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

/**
 * The message is a sentence, on one line: the outputs of git are whole in
 * getErrorOutput() and getOutput().
 */
class ProcessExceptionTest extends AbstractTestCase
{
    public function testTheMessageHasTheFirstLineOfTheErrorOutput(): void
    {
        $this->skipUnlessCliDriver();

        $repository = self::createFoobarRepository(false);
        file_put_contents($repository->getWorkingDir().'/AUTHORS', 'local change');

        try {
            $repository->run('checkout', ['origin/pagination']);
            $this->fail('The checkout would overwrite the local changes.');
        } catch (ProcessException $e) {
            $this->assertSame('The git command "checkout" failed (exit 1): error: Your local changes to the following files would be overwritten by checkout.', $e->getMessage());
            $this->assertStringContainsString("\tAUTHORS\n", $e->getErrorOutput());
        }
    }

    /**
     * git warns about a HOME it can't read before its error.
     */
    public function testTheMessageHasTheErrorAfterTheWarnings(): void
    {
        $this->skipUnlessCliDriver();

        $repository = self::createFoobarRepository(false);
        file_put_contents($repository->getWorkingDir().'/AUTHORS', 'local change');
        $home = self::createTempDir();
        mkdir($home.'/.config');
        $repository = new Repository($repository->getPath(), driver: self::getCliDriver(['HOME' => $home]));

        chmod($home.'/.config', 0);

        try {
            if (is_readable($home.'/.config')) {
                $this->markTestSkipped('The user of the tests reads any directory, like root.');
            }

            $repository->getWorkingCopy()->checkout('origin/pagination');
            $this->fail('The checkout would overwrite the local changes.');
        } catch (ProcessException $e) {
            $this->assertSame('The git command "checkout" failed (exit 1): error: Your local changes to the following files would be overwritten by checkout.', $e->getMessage());
            $this->assertStringStartsWith(\sprintf("warning: unable to access '%s/.config/git/", $home), $e->getErrorOutput());
        } finally {
            // Or the directory can't be deleted.
            chmod($home.'/.config', 0o700);
        }
    }

    #[DataProvider('provideErrorOutputs')]
    public function testTheMessageHasTheFirstError(string $errorOutput, string $message): void
    {
        $process = new Process([\PHP_BINARY, '-r', 'fwrite(STDERR, $argv[1]); exit(128);', '--', $errorOutput]);
        $process->run();

        $this->assertSame($message, new ProcessException($process, 'merge')->getMessage());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideErrorOutputs(): iterable
    {
        yield 'warning first' => ["warning: refname 'feature' is ambiguous.\nfatal: ambiguous object name: 'feature'\n", 'The git command "merge" failed (exit 128): fatal: ambiguous object name: \'feature\'.'];
        yield 'errors' => ["error: first\nfatal: second\n", 'The git command "merge" failed (exit 128): error: first.'];
        yield 'no error' => ["\nwarning: something\nmerge: nope - not something we can merge\n", 'The git command "merge" failed (exit 128): warning: something.'];
    }

    /**
     * git writes the conflicts of a merge on its standard output.
     */
    public function testTheMessageOfAConflictHasIt(): void
    {
        $this->skipUnlessCliDriver();

        $repository = self::createFoobarRepository(false);
        self::git($repository, 'config', ['user.name', 'Unit Test']);
        self::git($repository, 'config', ['user.email', 'unit_test@unit-test.com']);
        file_put_contents($repository->getWorkingDir().'/README.md', "mine\n");
        self::git($repository, 'commit', ['-q', '-a', '-m', 'Mine']);
        self::git($repository, 'checkout', ['-q', '-b', 'theirs', 'HEAD~1']);
        file_put_contents($repository->getWorkingDir().'/README.md', "theirs\n");
        self::git($repository, 'commit', ['-q', '-a', '-m', 'Theirs']);
        self::git($repository, 'checkout', ['-q', 'master']);

        try {
            $repository->getWorkingCopy()->merge('theirs');
            $this->fail('Both sides change README.md.');
        } catch (ProcessException $e) {
            $this->assertSame('The git command "merge" failed (exit 1): CONFLICT (content): Merge conflict in README.md.', $e->getMessage());
        }
    }

    public function testTheMessageOfAFailureWithoutErrorOutputEndsWithTheExitCode(): void
    {
        $this->skipUnlessCliDriver();

        try {
            self::createFoobarRepository()->run('diff', ['--exit-code', '--no-ext-diff', '--no-textconv', self::INITIAL_COMMIT, self::LONGFILE_COMMIT]);
            $this->fail('The commits differ.');
        } catch (ProcessException $e) {
            $this->assertSame('The git command "diff" failed (exit 1).', $e->getMessage());
            $this->assertSame('', $e->getErrorOutput());
            $this->assertStringContainsString('diff --git', $e->getOutput());
        }
    }

    /**
     * Like in 1.x, which had no other argument.
     */
    public function testTheCommandIsOptional(): void
    {
        $process = new Process([self::gitCommand(), 'not-work'], self::createTempDir(), self::gitEnvs());
        $process->run();

        $this->assertSame('The git command failed (exit 1): git: \'not-work\' is not a git command. See \'git --help\'.', new ProcessException($process)->getMessage());
    }
}
