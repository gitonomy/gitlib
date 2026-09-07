#!/usr/bin/env php
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

require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

const GIT_URL = 'https://github.com/git/git.git';
const BUILDS_PATH = __DIR__.'/git-builds';

// One v1.x and several v2.y releases, picked for how long and how widely each
// shipped as the default git on a major distro, rather than for a spread across
// git's history: v1.7.1 (RHEL/CentOS 6), v1.8.3.1 (RHEL/CentOS 7), v2.17.1 (Ubuntu
// 18.04), v2.25.1 (Ubuntu 20.04), v2.34.1 (Ubuntu 22.04), v2.43.0 (Ubuntu 24.04).
const DEFAULT_VERSIONS = ['v1.7.1', 'v1.8.3.1', 'v2.17.1', 'v2.25.1', 'v2.34.1', 'v2.43.0'];

function sh(array $args, ?string $cwd = null, ?string $logfile = null): Process
{
    $process = new Process($args, $cwd, timeout: null);

    if (null !== $logfile) {
        file_put_contents($logfile, '$ '.implode(' ', $args)."\n", \FILE_APPEND);
        $process->run(static function (string $type, string $data) use ($logfile): void {
            file_put_contents($logfile, $data, \FILE_APPEND);
        });
        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf('Command "%s" failed, see %s', implode(' ', $args), $logfile));
        }
    } else {
        $process->mustRun();
    }

    return $process;
}

function cachePath(): string
{
    return BUILDS_PATH.'/cache';
}

function versionPath(string $version): string
{
    return BUILDS_PATH.'/'.$version;
}

function gitBinary(string $version): string
{
    return versionPath($version).'/build/bin/git';
}

#[AsCommand(name: 'build', description: 'Build one or more git versions from source, ready to be used by the "test" command')]
final class BuildCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('versions', InputArgument::IS_ARRAY, 'git tags to build (defaults to the curated list)', DEFAULT_VERSIONS)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $versions = $input->getArgument('versions');

        if (!is_dir(BUILDS_PATH)) {
            mkdir(BUILDS_PATH);
        }

        if (is_dir(cachePath())) {
            $io->section('Updating cache from GitHub');
            sh(['git', 'fetch', '--quiet'], cachePath());
        } else {
            $io->section('Cloning git/git from GitHub');
            sh(['git', 'clone', '--quiet', GIT_URL, cachePath()]);
        }

        foreach ($versions as $version) {
            $io->section(sprintf('Building %s', $version));

            $path = versionPath($version);
            $lockfile = BUILDS_PATH.'/'.$version.'.lock';
            $logfile = BUILDS_PATH.'/'.$version.'.log';

            if (is_file($lockfile)) {
                $io->warning('A previous build was interrupted, rebuilding from scratch.');
                new Process(['rm', '-rf', $path])->mustRun();
                unlink($lockfile);
            }

            if (is_dir($path)) {
                $io->writeln('Already built, skipping.');

                continue;
            }

            touch($lockfile);
            mkdir($path, recursive: true);
            file_put_contents($logfile, '');

            $io->writeln(sprintf('Log: %s', $logfile));

            $source = $path.'/source';
            sh(['git', 'clone', '--shared', '--quiet', cachePath(), $source]);
            sh(['git', 'checkout', '--quiet', $version], $source);

            $prefix = $path.'/build';
            mkdir($prefix);

            $io->writeln('Compiling (autoconf, configure, make, make install)...');
            sh(['autoconf'], $source, $logfile);
            sh(['./configure', '--prefix='.$prefix], $source, $logfile);
            // gitlib only ever shells out to plumbing/porcelain in the main "git" binary,
            // so building the GUI/language extras is both unnecessary and, for a build
            // this old, unreliable on a modern machine:
            // - -std=gnu17: recent git releases declare an "unreachable" identifier that
            //   collides with the C23 unreachable() macro glibc/gcc now define by default.
            // - NO_OPENSSL: older git-imap-send.c uses OpenSSL 1.0 APIs (HMAC_CTX as a
            //   stack struct) removed in OpenSSL 3; the test suite is fully offline and
            //   never needs imap-send.
            // - NO_PYTHON, NO_TCLTK: skip the Python remote-helper bridge and git-gui/gitk,
            //   which need toolchains (Python 2, Tcl/Tk) this machine doesn't have.
            // All flags are passed to both invocations: git's Makefile forces a full
            // rebuild whenever they change between "make all" and "make install".
            $makeFlags = ['CFLAGS=-std=gnu17', 'NO_OPENSSL=1', 'NO_PYTHON=1', 'NO_TCLTK=1'];
            sh(['make', 'all', ...$makeFlags], $source, $logfile);
            sh(['make', 'install', ...$makeFlags], $source, $logfile);

            unlink($lockfile);
            $io->success(sprintf('%s built at %s', $version, gitBinary($version)));
        }

        return Command::SUCCESS;
    }
}

#[AsCommand(name: 'test', description: 'Run the test suite against one or more already-built git versions')]
final class TestCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('versions', InputArgument::IS_ARRAY, 'git tags to test against (defaults to the curated list)', DEFAULT_VERSIONS)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $versions = $input->getArgument('versions');

        // Old git binaries choke on config values introduced after their release (e.g.
        // "merge.conflictstyle = zdiff3", added in 2.35, is a fatal "unknown style" error
        // on anything older) if they pick up the invoking user's own ~/.gitconfig. Give
        // each git binary an empty, isolated HOME so only gitlib's own fixtures apply.
        $isolatedHome = sys_get_temp_dir().'/gitlib-test-git-versions-home-'.bin2hex(random_bytes(4));
        mkdir($isolatedHome);

        $results = [];

        foreach ($versions as $version) {
            $binary = gitBinary($version);

            if (!is_file($binary)) {
                $io->error(sprintf('%s is not built yet. Run the "build" command first.', $version));
                $results[] = [$version, 'not built'];

                continue;
            }

            $io->section(sprintf('Testing against %s', $version));
            $io->writeln(sprintf('Command: %s', $binary));

            $env = ['GIT_COMMAND' => $binary, 'HOME' => $isolatedHome, 'XDG_CONFIG_HOME' => $isolatedHome];
            $process = new Process(['vendor/bin/phpunit'], __DIR__, $env, timeout: null);
            $process->run(static function (string $type, string $data) use ($output): void {
                $output->write($data);
            });

            $results[] = [$version, $process->isSuccessful() ? 'ok' : 'FAILED'];
        }

        $io->table(['Version', 'Result'], $results);

        $failed = array_filter($results, static fn (array $result) => 'ok' !== $result[1]);

        return [] === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}

$app = new Application('gitlib multi-version git test tool');
$app->addCommands([new BuildCommand(), new TestCommand()]);
exit($app->run());
