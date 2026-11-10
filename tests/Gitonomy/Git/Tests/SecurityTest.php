<?php

/**
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gitonomy\Git\Tests;

use Gitonomy\Git\Admin;
use Gitonomy\Git\Driver\Cli\CliDriver;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\LogicException;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Repository;
use Gitonomy\Git\Util\Credentials;
use Gitonomy\Git\Util\RemoteUrl;
use Psr\Log\AbstractLogger;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * An application may get a revision, a URL or the name of a hook from its
 * users: git must not read them as options, and run nothing for them.
 */
class SecurityTest extends AbstractTest
{
    /**
     * Nothing listens on port 1: the commands fail.
     */
    private const URL = 'http://user:secret@127.0.0.1:1/repository.git';

    /**
     * @group legacy
     *
     * @dataProvider provideOutputCalls
     */
    public function testAnOptionWritingAFileIsRefused(\Closure $call)
    {
        $repository = self::createFoobarRepository(false);
        $file = self::createTempDir().'/written';

        try {
            $call($repository, '--output='.$file);
            $this->fail('--output is refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('writing a file', $e->getMessage());
        }

        $this->assertFileIsAbsent($file);
    }

    public static function provideOutputCalls()
    {
        yield 'Revision::getCommit()' => [function (Repository $repository, string $option) {
            $repository->getRevision($option)->getCommit();
        }];
        yield 'Revision::getLog()' => [function (Repository $repository, string $option) {
            $repository->getRevision($option)->getLog()->getCommits();
        }];
        yield 'Repository::getLog()' => [function (Repository $repository, string $option) {
            $repository->getLog($option)->getCommits();
        }];
        yield 'Repository::getLog(), among revisions' => [function (Repository $repository, string $option) {
            $repository->getLog(['master', $option])->getCommits();
        }];
        yield 'Repository::getDiff(), one argument' => [function (Repository $repository, string $option) {
            $repository->getDiff($option);
        }];
        yield 'Repository::getDiff(), two arguments' => [function (Repository $repository, string $option) {
            $repository->getDiff($option, 'master');
        }];
        yield 'Repository::getMergeBase()' => [function (Repository $repository, string $option) {
            $repository->getMergeBase($option, 'master');
        }];
        yield 'WorkingCopy::getDiff()' => [function (Repository $repository, string $option) {
            $repository->getWorkingCopy()->getDiff($option);
        }];
        yield 'Branch::moveTo()' => [function (Repository $repository, string $option) {
            $repository->getReferences()->getBranch('master')->moveTo($option);
        }];
        yield 'Branch::isMergedInto()' => [function (Repository $repository, string $option) {
            $repository->getReferences()->getBranch('master')->isMergedInto($option);
        }];
        yield 'ReferenceBag::createBranch()' => [function (Repository $repository, string $option) {
            $repository->getReferences()->createBranch('foo', $repository->getRevision($option));
        }];
    }

    /**
     * git takes the file as the next argument too.
     */
    public function testAnOptionWritingAFileIsRefusedInTwoArguments()
    {
        $repository = self::createFoobarRepository();
        $file = self::createTempDir().'/written';

        try {
            $repository->getLog(['--output', $file])->getCommits();
            $this->fail('--output is refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('writing a file', $e->getMessage());
        }

        $this->assertFileIsAbsent($file);
    }

    /**
     * @dataProvider provideBlameFileOptions
     */
    public function testBlameRefusesTheOptionsReadingOrWritingAFile(string $option)
    {
        $repository = self::createFoobarRepository(false);
        $file = self::createTempDir().'/secret';
        file_put_contents($file, "secret\n");

        try {
            $repository->getBlame($option.$file, 'README.md')->getLines();
            $this->fail('The option is refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('reading or writing a file', $e->getMessage());
        }
    }

    public static function provideBlameFileOptions()
    {
        yield 'contents' => ['--contents='];
        yield 'abbreviation of contents' => ['--con='];
        yield 'shortest abbreviation of contents, before git knew --color-lines' => ['--c='];
        yield 'ignore-revs-file' => ['--ignore-revs-file='];
        yield 'abbreviation of ignore-revs-file' => ['--ignore-revs='];
        yield 'revs-file' => ['-S'];
        yield 'revs-file in a group' => ['-wS'];
        yield 'output' => ['--output='];
    }

    /**
     * @group legacy
     */
    public function testCreatingAReferenceFromAnOptionIsRefused()
    {
        $repository = self::createFoobarRepository();
        $references = $repository->getReferences();

        // "git update-ref refs/heads/foo -d" deletes it.
        foreach (['createBranch' => 'refs/heads/foo', 'createTag' => 'refs/tags/foo'] as $method => $name) {
            $repository->run('update-ref', [$name, self::INITIAL_COMMIT]);

            try {
                $references->$method('foo', '-d');
                $this->fail('The dash is refused.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('"-d" starts with a dash, which git would read as an option.', $e->getMessage());
            }

            $this->assertSame(self::INITIAL_COMMIT, trim($repository->run('rev-parse', [$name])));
        }

        try {
            $references->update(new Branch($repository, 'refs/heads/foo', '-d'));
            $this->fail('The dash is refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(self::INITIAL_COMMIT, trim($repository->run('rev-parse', ['refs/heads/foo'])));
        }
    }

    public function testCheckoutNewBranchRefusesOptions()
    {
        $workingCopy = self::createFoobarRepository(false)->getWorkingCopy();

        foreach ([['--orphan=foo', 'master'], ['foo', '--force']] as [$branch, $startPoint]) {
            try {
                $workingCopy->checkoutNewBranch($branch, $startPoint);
                $this->fail('The dash is refused.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('starts with a dash', $e->getMessage());
            }
        }
    }

    /**
     * In a repository with a remote, "git ls-remote --upload-pack=..." runs
     * the command to list the references of the remote.
     *
     * @group legacy
     *
     * @dataProvider provideUrlChecks
     */
    public function testCheckingAUrlStartingWithADashRunsNothing(\Closure $check)
    {
        [$bare, $command, $file] = $this->createCommand();

        $repository = self::createEmptyRepository(false);
        $repository->run('remote', ['add', 'origin', $bare]);

        $cwd = getcwd();
        chdir($repository->getWorkingDir());

        try {
            $this->assertFalse($check('--upload-pack='.$command, $bare));
        } finally {
            chdir($cwd);
        }

        $this->assertFileIsAbsent($file);
    }

    /**
     * "git ls-remote" reads the configuration of the repository it runs in:
     * another user of the machine may create one in the temporary directory,
     * whose rewrites of URLs run a command. Here, it rewrites the URL to a
     * path that doesn't exist.
     */
    public function testARepositoryOfTheTemporaryDirectoryIsNotRead()
    {
        $planted = sys_get_temp_dir().'/.git';
        if (file_exists($planted)) {
            $this->markTestSkipped('The temporary directory holds a repository already.');
        }

        $url = self::createFoobarRepository()->getGitDir();
        Repository::initBare($planted, self::getDriver());
        file_put_contents($planted.'/config', sprintf("[url \"/nonexistent/\"]\n\tinsteadOf = %s\n", $url), FILE_APPEND);

        try {
            $this->assertTrue(Repository::isValidRepository($url, self::getDriver()));
            $this->assertTrue(Repository::isValidRepository('file://'.$url, self::getDriver()));
            $this->assertTrue(Repository::isValidRepositoryAndBranch($url, 'master', self::getDriver()));
            $this->assertFalse(Repository::isValidRepositoryAndBranch($url, 'none', self::getDriver()));
        } finally {
            self::deleteDir($planted);
        }
    }

    public static function provideUrlChecks()
    {
        $options = self::getOptions();
        $driver = self::getDriver();

        yield 'Admin::isValidRepository()' => [fn (string $url) => Admin::isValidRepository($url, $options)];
        // The second argument is the repository for git.
        yield 'Admin::isValidRepositoryAndBranch()' => [fn (string $url, string $bare) => Admin::isValidRepositoryAndBranch($url, $bare, $options)];
        yield 'Repository::isValidRepository()' => [fn (string $url) => Repository::isValidRepository($url, $driver)];
        yield 'Repository::isValidRepositoryAndBranch()' => [fn (string $url, string $bare) => Repository::isValidRepositoryAndBranch($url, $bare, $driver)];
    }

    /**
     * The path is the repository for git.
     *
     * @group legacy
     *
     * @dataProvider provideClones
     */
    public function testCloningAUrlStartingWithADashRunsNothing(\Closure $clone)
    {
        [$bare, $command, $file] = $this->createCommand();

        try {
            $clone('--upload-pack='.$command, $bare);
            $this->fail('The URL is refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('starts with a dash', $e->getMessage());
        }

        $this->assertFileIsAbsent($file);
    }

    public static function provideClones()
    {
        $options = self::getOptions();
        $driver = self::getDriver();

        yield 'Admin::cloneTo()' => [fn (string $url, string $path) => Admin::cloneTo($path, $url, true, $options)];
        yield 'Admin::cloneBranchTo()' => [fn (string $url, string $path) => Admin::cloneBranchTo($path, $url, 'master', true, $options)];
        yield 'Admin::mirrorTo()' => [fn (string $url, string $path) => Admin::mirrorTo($path, $url, $options)];
        yield 'Admin::cloneRepository()' => [fn (string $url, string $path) => Admin::cloneRepository($path, $url, [], $options)];
        yield 'Repository::cloneFrom()' => [fn (string $url, string $path) => Repository::cloneFrom($url, $path, null, $driver)];
        yield 'Repository::cloneBareFrom()' => [fn (string $url, string $path) => Repository::cloneBareFrom($url, $path, null, $driver)];
        yield 'Repository::mirrorFrom()' => [fn (string $url, string $path) => Repository::mirrorFrom($url, $path, $driver)];
    }

    /**
     * After the URL, git would read the path as an option too.
     *
     * @group legacy
     */
    public function testAPathMayStartWithADash()
    {
        [$bare, $command, $file] = $this->createCommand();
        $dir = self::createTempDir();
        $option = '--upload-pack='.basename($command);

        $cwd = getcwd();
        chdir($dir);

        try {
            $clone = Repository::cloneBareFrom($bare, $option, null, self::getDriver());
            $this->assertSame(realpath($dir.'/'.$option), $clone->getGitDir());

            $this->assertInstanceOf(Repository::class, Admin::cloneTo('-clone', $bare, true, self::getOptions()));
            $this->assertInstanceOf(Repository::class, Admin::init('-init', true, self::getOptions()));
            $this->assertInstanceOf(Repository::class, Repository::init('-working-copy', self::getDriver()));
            $this->assertInstanceOf(Repository::class, Repository::initBare('--separate-git-dir=foo', self::getDriver()));
        } finally {
            chdir($cwd);
        }

        $this->assertFileIsAbsent($file);
        $this->assertFileExists($dir.'/-clone/HEAD');
        $this->assertFileExists($dir.'/-init/HEAD');
        $this->assertFileExists($dir.'/-working-copy/.git/HEAD');
        $this->assertFileExists($dir.'/--separate-git-dir=foo/HEAD');
    }

    /**
     * git talks through a file descriptor it inherits from PHP, and waits on it.
     *
     * @group legacy
     */
    public function testAFileDescriptorUrlIsRefused()
    {
        $this->assertFalse(Admin::isValidRepository('fd::3', self::getOptions()));
        $this->assertFalse(Admin::isValidRepositoryAndBranch('fd::3', 'master', self::getOptions()));
        $this->assertFalse(Repository::isValidRepository('fd::3', self::getDriver()));
        $this->assertFalse(Repository::isValidRepositoryAndBranch('fd::3', 'master', self::getDriver()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('uses the "fd::" transport');

        Repository::cloneFrom('fd::3', self::createTempDir().'/clone', null, self::getDriver());
    }

    /**
     * @dataProvider provideHostsStartingWithADash
     */
    public function testAHostStartingWithADashIsRefused(string $url)
    {
        $this->assertStringContainsString('starting with a dash', RemoteUrl::unsafeReason($url));
    }

    public static function provideHostsStartingWithADash()
    {
        $command = '-oProxyCommand=touch${IFS}pwned';

        yield 'ssh' => ['ssh://'.$command.'/repository'];
        yield 'git+ssh' => ['git+ssh://'.$command.'/repository'];
        yield 'ssh+git' => ['ssh+git://'.$command.'/repository'];
        yield 'host between brackets' => ['ssh://['.$command.']/repository'];
        yield 'user' => ['ssh://'.$command.'@example.com/repository'];
        yield 'host after a user' => ['ssh://git@'.$command.'/repository'];
        yield 'address' => ['git@'.$command.':repository'];
        yield 'address between brackets' => ['['.$command.']:repository'];
        yield 'git, for the command of core.gitProxy' => ['git://'.$command.'/repository'];
        yield 'port of git, for the command of core.gitProxy' => ['git://example.com:'.$command.'/repository'];
        yield 'port after a host between brackets' => ['git://[example.com]:'.$command.'/repository'];
        yield 'port without a host' => ['git://:'.$command.'/repository'];
        yield 'encoded dash of a port' => ['git://example.com:%2D'.substr($command, 1).'/repository'];
        yield 'encoded dash' => ['ssh://%2D'.substr($command, 1).'/repository'];
        yield 'encoded dash, lowercase' => ['git+ssh://%2d'.substr($command, 1).'/repository'];
        yield 'encoded dash of a user' => ['ssh://%2D'.substr($command, 1).'@example.com/repository'];
        yield 'encoded brackets' => ['ssh://%5B'.$command.'%5D/repository'];
    }

    /**
     * @dataProvider provideUrlsGitReachesWithoutRunningACommand
     */
    public function testAUrlGitReachesWithoutRunningACommandIsAccepted(string $url)
    {
        $this->assertNull(RemoteUrl::unsafeReason($url));
    }

    public static function provideUrlsGitReachesWithoutRunningACommand()
    {
        yield 'ssh' => ['ssh://git@example.com:2222/repository.git'];
        yield 'host between brackets' => ['ssh://git@[::1]/repository.git'];
        yield 'host between brackets and port' => ['git://[::1]:9418/repository.git'];
        yield 'address' => ['git@example.com:repository.git'];
        yield 'dash in the path of an address' => ['git@example.com:-repository.git'];
        yield 'dash in the host' => ['ssh://git@my-host.example.com/repository.git'];
        yield 'encoded character in the user' => ['ssh://first%2Dlast@example.com/repository.git'];
        // Not reached through ssh: a token may start with a dash.
        yield 'https' => ['https://-token@example.com/repository.git'];
        yield 'local path' => ['/path/to/repository.git'];
        yield 'local path with a colon' => ['./a:b'];
    }

    /**
     * Recent versions of git hide the credentials of an http URL in their
     * error, not the old ones.
     */
    public function testAFailedCloneHidesTheCredentials()
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $this->markTestSkipped('The failing git is a shell script.');
        }

        $failingGit = self::createTempDir().'/git';
        file_put_contents($failingGit, "#!/bin/sh\necho \"fatal: unable to access '\$4'\" >&2\nexit 128\n");
        chmod($failingGit, 0700);

        try {
            Repository::cloneFrom(self::URL, self::createTempDir().'/clone', null, new CliDriver($failingGit));
            $this->fail('The clone fails.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("fatal: unable to access 'http://***@127.0.0.1:1/repository.git'", $e->getMessage());
            $this->assertStringNotContainsString('secret', (string) $e);
        }
    }

    public function testARefusedUrlHidesTheCredentials()
    {
        $this->assertSame('"fd::http://***@host" uses the "fd::" transport, which talks through a file descriptor of PHP.', RemoteUrl::unsafeReason('fd::http://user:secret@host'));
    }

    /**
     * git writes the name of a branch it refuses in its error output, and the
     * message holds the command line.
     */
    public function testTheMessageOfAProcessExceptionHidesTheCredentials()
    {
        try {
            self::createFoobarRepository()->run('branch', [self::URL]);
            $this->fail('A URL is no valid branch name.');
        } catch (ProcessException $e) {
            $this->assertStringContainsString('http://***@127.0.0.1:1/repository.git', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
            $this->assertStringContainsString(self::URL, $e->getErrorOutput(), 'The error output is the one of git');
        }
    }

    /**
     * @group legacy
     *
     * @dataProvider provideSlowCalls
     */
    public function testATimeoutKeepsItsClassAndHidesTheCredentials(\Closure $call)
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $this->markTestSkipped('The slow git is a shell script.');
        }

        $slowGit = self::createTempDir().'/git';
        file_put_contents($slowGit, "#!/bin/sh\nexec sleep 10\n");
        chmod($slowGit, 0700);

        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

        try {
            $call($slowGit, self::URL);
            $this->fail('git times out.');
        } catch (ProcessTimedOutException $e) {
            $this->assertStringContainsString('exceeded the timeout', $e->getMessage());
            $this->assertStringContainsString('http://***@127.0.0.1:1/repository.git', $e->getMessage());
            $this->assertStringNotContainsString('secret', (string) $e);
            if (\PHP_VERSION_ID >= 80200) {
                $this->assertTheTraceHidesTheCredentials($e);
            }
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs);
        }
    }

    public static function provideSlowCalls()
    {
        yield 'Repository::run()' => [function (string $git, string $url) {
            $repository = self::createEmptyRepository();
            (new Repository($repository->getGitDir(), null, new CliDriver($git, [], 0.2)))->run('fetch', [$url]);
        }];
        yield 'Repository::cloneFrom()' => [function (string $git, string $url) {
            Repository::cloneFrom($url, self::createTempDir().'/clone', null, new CliDriver($git, [], 0.2));
        }];
        yield 'Repository::isValidRepository()' => [function (string $git, string $url) {
            Repository::isValidRepository($url, new CliDriver($git, [], 0.2));
        }];
        yield 'Repository::isValidRepositoryAndBranch()' => [function (string $git, string $url) {
            Repository::isValidRepositoryAndBranch($url, 'master', new CliDriver($git, [], 0.2));
        }];
        yield 'Admin::isValidRepository()' => [function (string $git, string $url) {
            Admin::isValidRepository($url, ['command' => $git, 'process_timeout' => 0.2]);
        }];
        yield 'Admin::isValidRepositoryAndBranch()' => [function (string $git, string $url) {
            Admin::isValidRepositoryAndBranch($url, 'master', ['command' => $git, 'process_timeout' => 0.2]);
        }];
    }

    /**
     * The exception of Symfony Process holds the command line: here, longer
     * than what the system accepts. With PHP 8.1, which forks before running
     * git, the process fails instead of not starting.
     */
    public function testAProcessThatCannotStartHidesTheCredentials()
    {
        try {
            // Over the 128 KiB of an argument on Linux.
            self::createEmptyRepository()->run('fetch', [self::URL, str_repeat('a', 200 * 1024)]);
            $this->fail('The command line is too long.');
        } catch (ExceptionInterface|ProcessException $e) {
            $this->assertFalse(str_contains((string) $e, 'secret'), 'The message hides the credentials.');
        }
    }

    /**
     * @requires PHP 8.2
     *
     * @group legacy
     *
     * @dataProvider provideFailingCalls
     */
    public function testTheTracesHideTheCredentials(\Closure $call)
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

        try {
            $call(self::URL);
            $this->fail('The command fails.');
        } catch (RuntimeException $e) {
            $this->assertTheTraceHidesTheCredentials($e);
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs);
        }
    }

    public static function provideFailingCalls()
    {
        yield 'Repository::run()' => [function (string $url) {
            self::createEmptyRepository()->run('fetch', [$url]);
        }];
        yield 'Repository::cloneFrom()' => [function (string $url) {
            Repository::cloneFrom($url, self::createTempDir().'/clone', null, self::getDriver());
        }];
        yield 'Repository::cloneBareFrom()' => [function (string $url) {
            Repository::cloneBareFrom($url, self::createTempDir().'/clone', null, self::getDriver());
        }];
        yield 'Repository::mirrorFrom()' => [function (string $url) {
            Repository::mirrorFrom($url, self::createTempDir().'/clone', self::getDriver());
        }];
        yield 'Admin::cloneTo()' => [function (string $url) {
            Admin::cloneTo(self::createTempDir().'/clone', $url, true, self::getOptions());
        }];
        yield 'Admin::cloneBranchTo()' => [function (string $url) {
            Admin::cloneBranchTo(self::createTempDir().'/clone', $url, 'master', true, self::getOptions());
        }];
        yield 'Admin::mirrorTo()' => [function (string $url) {
            Admin::mirrorTo(self::createTempDir().'/clone', $url, self::getOptions());
        }];
        yield 'Admin::cloneRepository()' => [function (string $url) {
            Admin::cloneRepository(self::createTempDir().'/clone', $url, [], self::getOptions());
        }];
    }

    public function testTheLogsHideTheCredentials()
    {
        $logger = new class() extends AbstractLogger {
            public $messages = [];

            public function log($level, $message, array $context = [])
            {
                $this->messages[] = $message;
            }
        };

        $source = self::createFoobarRepository();
        $options = self::getOptions();
        $repository = new Repository($source->getGitDir(), null, new CliDriver($options['command'], $options['environment_variables'], 60, $logger));
        $repository->run('config', ['remote.foo.url', self::URL]);

        // The arguments of the command, and its output.
        $repository->run('remote', ['-v']);
        try {
            // Its error output.
            $repository->run('branch', [self::URL]);
            $this->fail('A URL is no valid branch name.');
        } catch (ProcessException $e) {
        }

        $logs = implode("\n", $logger->messages);
        $this->assertStringContainsString('http://***@127.0.0.1:1/repository.git', $logs);
        $this->assertStringNotContainsString('secret', $logs);
    }

    /**
     * @dataProvider provideTexts
     */
    public function testRedact(string $expected, string $text)
    {
        $this->assertSame($expected, Credentials::redact($text));
    }

    public static function provideTexts()
    {
        yield 'user and password' => ['https://***@example.com/a.git', 'https://user:secret@example.com/a.git'];
        yield 'token' => ['https://***@example.com/a.git', 'https://secret@example.com/a.git'];
        yield 'at sign in the password' => ['https://***@example.com/a.git', 'https://user:se@cret@example.com/a.git'];
        yield 'hash in the password' => ['https://***@example.com/a.git', 'https://user:se#cret@example.com/a.git'];
        yield 'question mark in the password' => ['https://***@example.com/a.git', 'https://user:se?cret@example.com/a.git'];
        yield 'in a sentence' => ["fatal: unable to access 'https://***@example.com/': error", "fatal: unable to access 'https://user:secret@example.com/': error"];
        yield 'twice' => ['http://***@a http://***@b', 'http://u:p@a http://u:p@b'];
        yield 'no credentials' => ['https://example.com/a.git', 'https://example.com/a.git'];
        yield 'address' => ['git@example.com:a.git', 'git@example.com:a.git'];
        yield 'an email after a URL' => ['https://example.com/ user@example.com', 'https://example.com/ user@example.com'];
        yield 'a URL after the host of another' => ['http://***@a://***@b', 'http://u:p@a://u:p@b'];
        yield 'the last part of a scheme' => ['git+ssh://***@example.com/a.git', 'git+ssh://user:secret@example.com/a.git'];
        yield 'uppercase scheme' => ['HTTPS://***@example.com/a.git', 'HTTPS://user:secret@example.com/a.git'];
        yield 'tabulation after the URL' => ["https://***@example.com\tuser@example.com", "https://user:secret@example.com\tuser@example.com"];
        yield 'no scheme, unlike 2.0' => ['fatal: unable to look up user:secret@example.com', 'fatal: unable to look up user:secret@example.com'];
        yield 'no scheme before the slashes' => ['://user:secret@example.com', '://user:secret@example.com'];
        yield 'at sign in the path' => ['https://example.com/user:secret@example.com', 'https://example.com/user:secret@example.com'];
    }

    /**
     * With a logger, the output of every command is redacted: megabytes, for
     * a blob. The regular expression reached the limits of PCRE on them, and
     * threw a TypeError.
     *
     * @dataProvider provideLongTexts
     */
    public function testRedactTakesALongText(string $expected, string $text)
    {
        $this->assertSame($expected, Credentials::redact($text));
    }

    public static function provideLongTexts()
    {
        yield 'a password of "@"' => ['https://***@example.com/a.git', 'https://x:'.str_repeat('a@', 1100000).'example.com/a.git'];
        $text = 'x:'.str_repeat('a@', 1100000);
        yield 'no scheme' => [$text, $text];
        $text = str_repeat('a-', 250000)."\n";
        yield 'a long word before a URL' => [$text.'https://***@example.com', $text.'https://user:secret@example.com'];
        $text = str_repeat('1+', 1000000);
        yield 'a long scheme without a letter' => [$text.'://user:secret@example.com', $text.'://user:secret@example.com'];
        $text = str_repeat("a.b-c+d e:f g@h\n", 100000);
        yield 'no credentials' => [$text, $text];
    }

    public function testTheLogsTakeALongOutput()
    {
        $logger = new class() extends AbstractLogger {
            public $messages = [];

            public function log($level, $message, array $context = [])
            {
                $this->messages[] = $message;
            }
        };

        $options = self::getOptions();
        $repository = new Repository(self::createFoobarRepository()->getGitDir(), null, new CliDriver($options['command'], $options['environment_variables'], 60, $logger));
        $content = 'https://user:secret'.str_repeat('@a', 1100000).'@example.com/a.git';
        file_put_contents($file = self::createTempDir().'/blob', $content);
        $hash = trim($repository->run('hash-object', ['-w', $file]));

        $this->assertSame($content, $repository->getBlob($hash)->getContent());
        $this->assertStringNotContainsString('user:secret', implode("\n", $logger->messages));
    }

    /**
     * @dataProvider provideHookCalls
     */
    public function testAHookNameLeadingOutOfTheHooksDirectoryIsRefused(\Closure $call, string $name)
    {
        $repository = self::createEmptyRepository();
        $hooks = $repository->getHooks();
        $config = file_get_contents($repository->getGitDir().'/config');

        try {
            $call($hooks, $name);
            $this->fail('The name is refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('leads out of the hooks directory', $e->getMessage());
        }

        $this->assertSame($config, file_get_contents($repository->getGitDir().'/config'));
        $this->assertFileIsAbsent($repository->getGitDir().'/created');
    }

    public static function provideHookCalls()
    {
        $calls = [
            'has' => fn ($hooks, $name) => $hooks->has($name),
            'get' => fn ($hooks, $name) => $hooks->get($name),
            'remove' => fn ($hooks, $name) => $hooks->remove($name),
            'set' => fn ($hooks, $name) => $hooks->set($name, 'content'),
            'setSymlink' => fn ($hooks, $name) => $hooks->setSymlink($name, '/etc/hostname'),
        ];

        foreach ($calls as $method => $call) {
            yield $method.' of the configuration' => [$call, '../config'];
            yield $method.' of a new file' => [$call, '../created'];
            yield $method.' through a directory' => [$call, 'foo/../../config'];
            yield $method.' of the parent' => [$call, '..'];
            yield $method.' with a backslash' => [$call, '..\\config'];
        }
    }

    /**
     * A link to a file not deployed yet: set() wrote through it, out of the
     * hooks directory.
     */
    public function testADanglingLinkIsAHook()
    {
        $repository = self::createEmptyRepository();
        $hooks = $repository->getHooks();
        $target = self::createTempDir().'/missing';
        symlink($target, $repository->getGitDir().'/hooks/pre-commit');

        $this->assertTrue($hooks->has('pre-commit'));

        try {
            $hooks->set('pre-commit', 'content');
            $this->fail('The hook exists.');
        } catch (LogicException $e) {
            $this->assertFileIsAbsent($target);
        }

        try {
            $hooks->setSymlink('pre-commit', '/etc/hostname');
            $this->fail('The hook exists.');
        } catch (LogicException $e) {
            $this->assertSame($target, readlink($repository->getGitDir().'/hooks/pre-commit'));
        }

        try {
            $hooks->get('pre-commit');
            $this->fail('The hook has no content.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Hook named "pre-commit" is not present', $e->getMessage());
        }

        $hooks->remove('pre-commit');
        $this->assertFalse(is_link($repository->getGitDir().'/hooks/pre-commit'));
    }

    /**
     * @dataProvider provideWorkingTreeReads
     */
    public function testTheCommandOfCoreFsmonitorIsNotRun(\Closure $read)
    {
        $repository = self::createFoobarRepository(false);
        [, $command, $file] = $this->createCommand();
        $repository->run('config', ['core.fsmonitor', $command]);

        $read($repository);

        $this->assertFileIsAbsent($file);
    }

    public static function provideWorkingTreeReads()
    {
        yield 'WorkingCopy::getUntrackedFiles()' => [fn (Repository $repository) => $repository->getWorkingCopy()->getUntrackedFiles()];
        yield 'WorkingCopy::getDiffPending()' => [fn (Repository $repository) => $repository->getWorkingCopy()->getDiffPending()];
        yield 'WorkingCopy::getDiffStaged()' => [fn (Repository $repository) => $repository->getWorkingCopy()->getDiffStaged()];
        yield 'Commit::getDiff()' => [fn (Repository $repository) => $repository->getHeadCommit()->getDiff()];
        yield 'Repository::run()' => [fn (Repository $repository) => $repository->run('status')];
    }

    /**
     * What gitlib sets comes after the values of the application, and of the
     * "git -c" running PHP, which git passes through the same variable.
     */
    public function testTheConfigurationParametersOfTheApplicationAreKept()
    {
        $source = self::createEmptyRepository();
        $options = self::getOptions();
        $repository = function (array $env) use ($source, $options) {
            return new Repository($source->getGitDir(), null, new CliDriver($options['command'], $env + $options['environment_variables']));
        };

        $this->assertSame("foo\n", $repository(['GIT_CONFIG_PARAMETERS' => "'user.name=foo'"])->run('config', ['user.name']));
        $this->assertSame("false\n", $repository(['GIT_CONFIG_PARAMETERS' => "'core.fsmonitor=/command'"])->run('config', ['core.fsmonitor']));
        $this->assertSame("false\n", $repository(['GIT_CONFIG_PARAMETERS' => false])->run('config', ['core.fsmonitor']));

        putenv("GIT_CONFIG_PARAMETERS='user.name=inherited'");

        try {
            $this->assertSame("inherited\n", $source->run('config', ['user.name']));
        } finally {
            putenv('GIT_CONFIG_PARAMETERS');
        }
    }

    /**
     * Unlike 2.0, 1.x reads them by default, as "git replace --graft" relies
     * on them: the changelog of 1.7.1 points to this variable.
     */
    public function testTheReplacementsOfObjectsCanBeIgnored()
    {
        $source = self::createFoobarRepository();
        $message = $source->getCommit(self::LONGMESSAGE_COMMIT)->getMessage();
        $source->run('replace', [self::LONGMESSAGE_COMMIT, self::INITIAL_COMMIT]);

        $read = function (array $environmentVariables) use ($source) {
            $repository = new Repository($source->getGitDir(), null, new CliDriver(environmentVariables: $environmentVariables));

            return $repository->getCommit(self::LONGMESSAGE_COMMIT)->getMessage();
        };

        $this->assertNotSame($message, $read([]));
        $this->assertSame($message, $read(['GIT_NO_REPLACE_OBJECTS' => '1']));
    }

    public function testDeletingASymbolicReferenceKeepsTheBranch()
    {
        $repository = self::createFoobarRepository();
        $repository->run('update-ref', ['refs/remotes/origin/master', self::INITIAL_COMMIT]);
        $repository->run('symbolic-ref', ['refs/remotes/origin/HEAD', 'refs/remotes/origin/master']);
        $repository->run('symbolic-ref', ['refs/heads/alias', 'refs/heads/master']);

        $references = $repository->getReferences();
        $references->delete('refs/remotes/origin/HEAD');
        $references->delete('refs/heads/alias');

        $this->assertSame(self::INITIAL_COMMIT, trim($repository->run('rev-parse', ['refs/remotes/origin/master'])));
        $this->assertStringNotContainsString('refs/remotes/origin/HEAD', $repository->run('show-ref'));
        $this->assertStringContainsString('refs/heads/master', $repository->run('show-ref'));
        $this->assertStringNotContainsString('refs/heads/alias', $repository->run('show-ref'));
    }

    /**
     * @group legacy
     */
    public function testDeletingADetachedHeadIsRefused()
    {
        $repository = self::createFoobarRepository(false);
        $repository->run('checkout', ['-q', self::LONGFILE_COMMIT]);

        try {
            $repository->getReferences()->delete('HEAD');
            $this->fail('A detached HEAD is kept.');
        } catch (InvalidArgumentException $e) {
            $this->assertFileExists($repository->getGitDir().'/HEAD');
        }
    }

    private function assertTheTraceHidesTheCredentials(\Throwable $e): void
    {
        // The frames of gitlib: the ones of the test hold the URL too.
        $frames = array_filter($e->getTrace(), fn (array $frame) => 1 === preg_match('/^Gitonomy\\\\Git\\\\(?!Tests)/', $frame['class'] ?? ''));
        $args = array_merge(...array_map(fn (array $frame) => $frame['args'] ?? [], $frames));
        $this->assertNotSame([], $args, 'The trace holds the arguments');

        array_walk_recursive($args, function ($arg) {
            if (is_string($arg)) {
                $this->assertStringNotContainsString('secret', $arg);
            }
        });
    }

    /**
     * Creates a bare repository, and a command writing a file.
     *
     * @return string[] The path of the repository, of the command, and of the file
     */
    private function createCommand(): array
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $this->markTestSkipped('The command is a shell script.');
        }

        $bare = self::createFoobarRepository()->getGitDir();
        $dir = self::createTempDir();
        $command = $dir.'/command';
        file_put_contents($command, "#!/bin/sh\ntouch '$dir/run'\nexit 1\n");
        chmod($command, 0700);

        return [$bare, $command, $dir.'/run'];
    }

    private function assertFileIsAbsent(string $file): void
    {
        $this->assertFalse(file_exists($file), sprintf('"%s" does not exist.', $file));
    }
}
