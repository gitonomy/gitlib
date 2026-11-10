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

namespace Gitonomy\Git\Driver\Cli;

use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Util\Credentials;
use Gitonomy\Git\Util\RemoteUrl;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Configures how gitlib runs git: pass it as the "driver" argument of
 * Repository, and of its static methods creating a repository.
 *
 * Its constructor is the one of the CliDriver of gitlib 2.0, to write code
 * running with both versions.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
final class CliDriver
{
    /**
     * A path under a file is never a directory, whatever another user of the
     * machine creates: git finds no repository there.
     */
    private const NO_GIT_DIR = __FILE__.'/none';

    /**
     * @param string                                  $command              The git binary
     * @param array<string, string|\Stringable|false> $environmentVariables Set on every git process, false removing an inherited one
     * @param float|null                              $processTimeout       In seconds, for the whole process and without output; null for none
     * @param LoggerInterface|null                    $logger               Receives every git command a repository runs, with its output
     */
    public function __construct(
        private string $command = 'git',
        private array $environmentVariables = [],
        private ?float $processTimeout = 3600,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @internal For the deprecated options of Admin and Repository::cloneTo(), to be removed in 2.0
     */
    public static function fromOptions(array $options): self
    {
        $options = array_merge([
            'environment_variables' => defined('PHP_WINDOWS_VERSION_BUILD') ? ['PATH' => getenv('PATH')] : [],
            'command'               => 'git',
            'process_timeout'       => 3600,
        ], $options);

        return new self($options['command'], $options['environment_variables'], $options['process_timeout']);
    }

    /**
     * @internal For Repository, which takes options in 1.x
     *
     * @return array{command: string, environment_variables: array<string, string|\Stringable|false>, process_timeout: float|null, logger: LoggerInterface|null}
     */
    public function getOptions(): array
    {
        return [
            'command'               => $this->command,
            'environment_variables' => $this->environmentVariables,
            'process_timeout'       => $this->processTimeout,
            'logger'                => $this->logger,
        ];
    }

    /**
     * @internal For Repository::init()
     *
     * @throws RuntimeException
     */
    public function init(string $path): void
    {
        // --: a path may start with a dash.
        $this->initRepository(['-q', '--', $path]);
    }

    /**
     * @internal For Repository::initBare()
     *
     * @throws RuntimeException
     */
    public function initBare(string $path): void
    {
        $this->initRepository(['-q', '--bare', '--', $path]);
    }

    /**
     * @internal For Repository::cloneFrom()
     *
     * @throws RuntimeException
     */
    public function cloneFrom(#[\SensitiveParameter] string $url, string $path, ?string $branch): void
    {
        $this->cloneRepository($url, $path, null === $branch ? [] : ['--branch', $branch]);
    }

    /**
     * @internal For Repository::cloneBareFrom()
     *
     * @throws RuntimeException
     */
    public function cloneBareFrom(#[\SensitiveParameter] string $url, string $path, ?string $branch): void
    {
        $this->cloneRepository($url, $path, null === $branch ? ['--bare'] : ['--branch', $branch, '--bare']);
    }

    /**
     * @internal For Repository::mirrorFrom()
     *
     * @throws RuntimeException
     */
    public function mirrorFrom(#[\SensitiveParameter] string $url, string $path): void
    {
        $this->cloneRepository($url, $path, ['--mirror']);
    }

    /**
     * @internal For Repository::isValidRepository()
     */
    public function isValidRepository(#[\SensitiveParameter] string $url): bool
    {
        if (null !== RemoteUrl::unsafeReason($url)) {
            return false;
        }

        $process = $this->createLsRemoteProcess([], $url, []);
        Credentials::runProcess($process);

        return $process->isSuccessful();
    }

    /**
     * @internal For Repository::isValidRepositoryAndBranch()
     */
    public function isValidRepositoryAndBranch(#[\SensitiveParameter] string $url, string $branch): bool
    {
        if (null !== RemoteUrl::unsafeReason($url)) {
            return false;
        }

        // git lists the branches whose name ends with the pattern: "release" matches "feature/release".
        $process = $this->createLsRemoteProcess(['--heads'], $url, ['refs/heads/'.$branch]);
        Credentials::runProcess($process);
        if (!$process->isSuccessful()) {
            return false;
        }

        foreach (explode("\n", $process->getOutput()) as $line) {
            if (str_ends_with($line, "\trefs/heads/".$branch)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @internal For the deprecated Admin, to be removed in 2.0
     *
     * @param string[] $args Arguments of ``git clone``
     *
     * @throws RuntimeException
     */
    public function cloneRepository(#[\SensitiveParameter] string $url, string $path, array $args): void
    {
        $reason = RemoteUrl::unsafeReason($url);
        if (null !== $reason) {
            throw new RuntimeException(sprintf('Error while initializing repository: %s', $reason));
        }

        // --: a path may start with a dash.
        $process = $this->createProcess('clone', array_merge(['-q'], $args, ['--', $url, $path]));
        Credentials::runProcess($process);

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf('Error while initializing repository: %s', Credentials::redact($process->getErrorOutput())));
        }
    }

    /**
     * @internal For the deprecated Admin, to be removed in 2.0
     *
     * @param string[] $args
     */
    public function createProcess(string $command, array $args): Process
    {
        return $this->createGitProcess(array_merge([$command], $args));
    }

    /**
     * Like in 2.0, out of the working directory of PHP, which may be a
     * repository, like the checkout of an application: ls-remote would read
     * its configuration, and a relative path as the name of one of its remotes.
     *
     * --git-dir keeps git from looking for a repository, and reading its
     * configuration: another user of the machine may create a ".git" in the
     * temporary directory, whose rewrites of URLs run a command.
     *
     * @param string[] $options
     * @param string[] $patterns
     */
    private function createLsRemoteProcess(array $options, string $url, array $patterns): Process
    {
        $process = $this->createGitProcess(array_merge(['--git-dir', self::NO_GIT_DIR, 'ls-remote'], $options, [self::absoluteUrl($url)], $patterns));
        $process->setWorkingDirectory(sys_get_temp_dir());

        return $process;
    }

    /**
     * @param string[] $args The options of git, its command, and the arguments of the command
     */
    private function createGitProcess(array $args): Process
    {
        $process = new Process(array_merge([$this->command], $args));
        $process->setEnv($this->environmentVariables);
        $process->setTimeout($this->processTimeout);
        $process->setIdleTimeout($this->processTimeout);

        return $process;
    }

    /**
     * A relative path, relative to the working directory of PHP.
     */
    private static function absoluteUrl(string $url): string
    {
        if (str_starts_with($url, '/') || 1 === preg_match('#^[a-zA-Z]:[\\\\/]#', $url)) {
            return $url;
        }

        // Like git: a URL, or an address like "host:path", has a colon before any slash.
        $colon = strpos($url, ':');
        $slash = strpos($url, '/');
        if (false !== $colon && (false === $slash || $colon < $slash)) {
            return $url;
        }

        $cwd = getcwd();

        return false === $cwd ? $url : $cwd.'/'.$url;
    }

    /**
     * @param string[] $args
     *
     * @throws RuntimeException
     */
    private function initRepository(array $args): void
    {
        $process = $this->createProcess('init', $args);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf("Error on repository initialization, command wasn't successful (%s). Error output:\n%s", $process->getCommandLine(), $process->getErrorOutput()));
        }
    }
}
