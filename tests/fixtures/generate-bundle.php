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

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

const BUNDLE_PATH = __DIR__.'/foobar.bundle';
const REFS_PATH = __DIR__.'/bundle-refs.txt';

function git(array $args, ?string $cwd = null): Process
{
    $process = new Process(['git', ...$args], $cwd);
    $process->mustRun();

    return $process;
}

function bundleRefs(): array
{
    return array_values(array_filter(explode("\n", trim(file_get_contents(REFS_PATH)))));
}

#[AsCommand(name: 'extract', description: 'Clone the fixture bundle to a working directory, ready for new commits, branches or tags')]
final class ExtractCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::OPTIONAL, 'Where to clone the fixture', sys_get_temp_dir().'/foobar-fixture-'.bin2hex(random_bytes(4)));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dest = $input->getArgument('path');

        if (file_exists($dest)) {
            $io->error(sprintf('Destination "%s" already exists.', $dest));

            return Command::FAILURE;
        }

        git(['clone', '--quiet', BUNDLE_PATH, $dest]);

        $localBranches = explode("\n", trim(git(['branch', '--format=%(refname:short)'], $dest)->getOutput()));

        // Full ref names, not `--format=%(refname:short)`: git shortens the symbolic
        // refs/remotes/origin/HEAD pointer to a bare "origin" on some git versions, which
        // would otherwise be mistaken for a real branch called "origin".
        $refs = trim(git(['for-each-ref', '--format=%(refname)', 'refs/remotes/origin'], $dest)->getOutput());
        foreach (explode("\n", $refs) as $ref) {
            if ('' === $ref) {
                continue;
            }
            $local = preg_replace('#^refs/remotes/origin/#', '', $ref);
            if ('HEAD' === $local || in_array($local, $localBranches, true)) {
                // Symbolic HEAD pointer, or already checked out as the clone's default branch.
                continue;
            }
            git(['branch', '--track', $local, "origin/{$local}"], $dest);
        }

        $io->success('Fixture extracted.');
        $io->writeln([
            sprintf('Path: %s', $dest),
            '',
            'Make your changes there (commits, branches, tags), then run:',
            sprintf('  %s build %s', $_SERVER['argv'][0], $dest),
        ]);

        return Command::SUCCESS;
    }
}

#[AsCommand(name: 'build', description: 'Rebuild tests/fixtures/foobar.bundle from a working directory produced by "extract"')]
final class BuildCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'The working directory produced by "extract"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $src = $input->getArgument('path');

        if (!is_dir($src)) {
            $io->error(sprintf('Source "%s" does not exist. Run "extract" first.', $src));

            return Command::FAILURE;
        }

        $tmpBundle = tempnam(sys_get_temp_dir(), 'foobar_bundle_');
        git(['bundle', 'create', $tmpBundle, ...bundleRefs()], $src);
        git(['bundle', 'verify', $tmpBundle]);

        copy($tmpBundle, BUNDLE_PATH);
        unlink($tmpBundle);

        $io->success('Bundle rebuilt.');
        $io->writeln([
            sprintf('Path: %s', BUNDLE_PATH),
            '',
            sprintf('If you added a ref not in %s, update that file first and re-run build.', REFS_PATH),
            'Otherwise, update the commit constants in AbstractTestCase if needed, then run:',
            '  tests/fixtures/verify-bundle.sh',
        ]);

        return Command::SUCCESS;
    }
}

$app = new Application('gitlib fixture bundle tool');
$app->addCommands([new ExtractCommand(), new BuildCommand()]);
exit($app->run());
