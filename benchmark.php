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

use Gitonomy\Git\Driver\Cli\CliDriver;
use Gitonomy\Git\Driver\DriverInterface;
use Gitonomy\Git\Driver\Ffi\LibGit2Driver;
use Gitonomy\Git\Repository;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * @return array<string, Closure(Repository): mixed> The operations to compare
 */
function operations(string $head, string $commit, string $file): array
{
    return [
        'Resolve HEAD' => static fn (Repository $repository) => $repository->getHeadCommit()->getHash(),
        'Read a commit' => static fn (Repository $repository) => $repository->getCommit($commit)->getMessage(),
        'Log 100 commits' => static fn (Repository $repository) => $repository->getLog($head, null, 0, 100)->getCommits(),
        'Log 100 commits of a directory' => static fn (Repository $repository) => $repository->getLog($head, dirname($file), 0, 100)->getCommits(),
        'Last commit of a file' => static fn (Repository $repository) => $repository->getCommit($head)->getLastModification($file)->getHash(),
        'Count the commits of HEAD' => static fn (Repository $repository) => $repository->getLog($head)->countCommits(),
        'List the branches' => static fn (Repository $repository) => $repository->getReferences()->getBranches(),
        'Read a file' => static fn (Repository $repository) => $repository->getCommit($head)->getTree()->resolvePath($file)->getContent(),
        'Diff a commit' => static fn (Repository $repository) => $repository->getCommit($commit)->getDiff()->getFiles(),
        'List the branches including a commit' => static fn (Repository $repository) => $repository->getCommit($commit)->getIncludingBranches(),
        'Blame a file' => static fn (Repository $repository) => $repository->getBlame($head, $file)->getLines(),
    ];
}

/**
 * @param list<int|float> $values
 */
function median(array $values): float
{
    sort($values);
    $middle = intdiv(count($values), 2);

    return count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
}

// One measure, in the process measure() starts.
if (false !== $measure = getenv('GITLIB_BENCHMARK_MEASURE')) {
    /** @var class-string<DriverInterface> $driver */
    [$path, $driver, $operation, $head, $commit, $file, $iterations] = json_decode($measure, true, flags: \JSON_THROW_ON_ERROR);
    $run = operations($head, $commit, $file)[$operation];

    // Warms up, and fails before timing anything when the operation throws.
    $run(new Repository($path, driver: new $driver()));

    $durations = [];
    for ($i = 0; $i < $iterations; ++$i) {
        // A new Repository every time, like a web request would open it.
        $start = hrtime(true);
        $run(new Repository($path, driver: new $driver()));
        $durations[] = hrtime(true) - $start;
    }

    echo median($durations) / 1e6;

    exit(0);
}

/**
 * Measures an operation in a process of its own: libgit2's caches don't
 * carry over from one operation to the next, and an operation that takes too
 * long can be stopped.
 *
 * @param class-string<DriverInterface> $driver
 *
 * @return float|null The median duration, in milliseconds, or null when the measure took more than $timeout seconds
 */
function measure(string $path, string $driver, string $operation, string $head, string $commit, string $file, int $iterations, int $timeout): ?float
{
    $process = new Process([\PHP_BINARY, __FILE__], env: ['GITLIB_BENCHMARK_MEASURE' => json_encode([$path, $driver, $operation, $head, $commit, $file, $iterations])], timeout: $timeout);

    try {
        return (float) $process->mustRun()->getOutput();
    } catch (ProcessTimedOutException) {
        return null;
    }
}

/**
 * Times the loading of libgit2, in new PHP processes: a process pays it once.
 *
 * @return float The median duration, in milliseconds
 */
function measureLibraryLoading(int $iterations): float
{
    $code = <<<'PHP'
        require $argv[1];
        $start = hrtime(true);
        new Gitonomy\Git\Driver\Ffi\LibGit2Driver();
        echo hrtime(true) - $start;
        PHP;

    $durations = [];
    for ($i = 0; $i < $iterations; ++$i) {
        $durations[] = (int) new Process([\PHP_BINARY, '-r', $code, __DIR__.'/vendor/autoload.php'])->mustRun()->getOutput();
    }

    return median($durations) / 1e6;
}

new SingleCommandApplication()
    ->setName('Compare CliDriver and LibGit2Driver on read operations')
    ->addArgument('repository', InputArgument::REQUIRED, 'The repository to read')
    ->addArgument('file', InputArgument::REQUIRED, 'A file of HEAD, to log, read and blame')
    ->addOption('iterations', null, InputOption::VALUE_REQUIRED, 'The number of timed runs of each operation, of which the median is kept', '10')
    ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'The time after which the measure of an operation is stopped, in seconds', '60')
    ->setCode(static function (InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('repository');
        $file = $input->getArgument('file');
        $iterations = (int) $input->getOption('iterations');
        $timeout = (int) $input->getOption('timeout');

        $repository = new Repository($path);
        $head = $repository->getHeadCommit()->getHash();
        // The last commit that changed $file: its diff isn't empty.
        $commit = $repository->getHeadCommit()->getLastModification($file)->getHash();

        $io->title(sprintf('%s (HEAD: %s, %d commits, %d references)', $path, $head, $repository->getLog($head)->countCommits(), count($repository->getReferences())));
        $io->writeln(sprintf('PHP %s, %s, median of %d runs.', \PHP_VERSION, trim(new Process(['git', '--version'])->mustRun()->getOutput()), $iterations));

        $format = static fn (?float $duration): string => null === $duration ? sprintf('> %d s', $timeout) : sprintf('%.1f ms', $duration);
        $rows = [['Load libgit2 (once per process)', '-', $format(measureLibraryLoading($iterations)), '-']];
        foreach (array_keys(operations($head, $commit, $file)) as $operation) {
            $cli = measure($path, CliDriver::class, $operation, $head, $commit, $file, $iterations, $timeout);
            $libGit2 = measure($path, LibGit2Driver::class, $operation, $head, $commit, $file, $iterations, $timeout);
            $rows[] = [$operation, $format($cli), $format($libGit2), null === $cli || null === $libGit2 ? '-' : sprintf('%.1f', $cli / $libGit2)];
        }

        $io->table(['Operation', 'CliDriver', 'LibGit2Driver', 'Ratio'], $rows);

        return 0;
    })
    ->run()
;
