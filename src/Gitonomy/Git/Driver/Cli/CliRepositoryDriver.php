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

namespace Gitonomy\Git\Driver\Cli;

use Gitonomy\Git\Driver\Cli\Parser\BlameParser;
use Gitonomy\Git\Driver\Cli\Parser\LogParser;
use Gitonomy\Git\Driver\Cli\Parser\ReferenceParser;
use Gitonomy\Git\Driver\Cli\Parser\TagParser;
use Gitonomy\Git\Driver\Cli\Parser\TreeParser;
use Gitonomy\Git\Driver\Data\CommitData;
use Gitonomy\Git\Driver\Data\DiffData;
use Gitonomy\Git\Driver\Data\ObjectType;
use Gitonomy\Git\Driver\Data\TagData;
use Gitonomy\Git\Driver\RepositoryDriverInterface;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\FastForward;
use Gitonomy\Git\Parser\DiffParser;
use Gitonomy\Git\Util\LeadingDash;
use Gitonomy\Git\Util\ReferenceName;
use Symfony\Component\Process\Process;

/**
 * Runs a git process for every operation on a repository.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @internal
 */
final readonly class CliRepositoryDriver implements RepositoryDriverInterface
{
    /**
     * Sets back to git's defaults the configuration of the user that changes
     * what git prints, reads or writes, where git 1.8.4 has no option for it.
     * A git version that doesn't know a key ignores it.
     *
     * branch.autoSetupMerge and branch.autoSetupRebase: a branch created from
     * a remote branch tracks it, without rebasing, like with LibGit2Driver.
     * diff.indentHeuristic is the default since git 2.14, and LibGit2Driver's.
     */
    private const array CONFIG = [
        '-c', 'branch.autoSetupMerge=true',
        '-c', 'branch.autoSetupRebase=never',
        '-c', 'branch.sort=refname',
        '-c', 'diff.indentHeuristic=true',
        '-c', 'diff.suppressBlankEmpty=false',
        '-c', 'log.follow=false',
        '-c', 'log.showSignature=false',
    ];

    /**
     * -z: the raw information gives the names as they are, without quoting.
     * --no-abbrev: before git 2.16, --raw abbreviates hashes with a trailing "...".
     * The others set back to git's defaults the configuration "git diff" reads,
     * unlike diff-tree: diff.algorithm, diff.context, diff.external, diff.noprefix,
     * diff.submodule...
     */
    private const array DIFF_ARGS = [
        '-r', '-p', '--raw', '-z', '-M', '--full-index', '--no-abbrev',
        '--diff-algorithm=myers', '--dst-prefix=b/', '--inter-hunk-context=0', '--no-color', '--no-ext-diff', '--no-textconv', '-O/dev/null', '--src-prefix=a/', '--submodule=short', '-U3',
    ];

    /**
     * The tree without any file: git knows it, even when no commit has it.
     */
    private const string EMPTY_TREE = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';

    public function __construct(
        private GitRunner $runner,
        private string $gitDir,
        private ?string $workingDir,
    ) {
    }

    public function getGitDir(): string
    {
        return $this->gitDir;
    }

    public function getWorkingDir(): ?string
    {
        return $this->workingDir;
    }

    public function getHeadReference(): ?string
    {
        $process = $this->execute('symbolic-ref', ['-q', 'HEAD']);

        // -q: git exits with 1, without output, when HEAD is detached.
        if (1 === $process->getExitCode() && '' === $process->getOutput()) {
            return null;
        }

        if (!$process->isSuccessful()) {
            throw new ProcessException($process, 'symbolic-ref');
        }

        return trim($process->getOutput());
    }

    public function resolveRevision(string $revision): string
    {
        LeadingDash::assertNone($revision);

        $process = $this->execute('rev-parse', ['--verify', '--quiet', $revision]);

        // --quiet: git exits with 1 when nothing has this name, or when it
        // names several objects, like the range "a...b", which it prints.
        if (1 === $process->getExitCode()) {
            throw new ReferenceNotFoundException(\sprintf('The revision "%s" does not exist.', $revision));
        }

        if (!$process->isSuccessful()) {
            throw new ProcessException($process, 'rev-parse');
        }

        return trim($process->getOutput());
    }

    public function getShortHash(string $hash): string
    {
        LeadingDash::assertNone($hash);

        // --: a file of the working tree may have the name of the hash.
        return trim($this->runOnObject($hash, null, 'log', ['--abbrev-commit', '--format=%h', '-n', '1', $hash, '--']));
    }

    public function getCommitData(string $hash): CommitData
    {
        LeadingDash::assertNone($hash);

        $parser = new LogParser();
        $parser->parse($this->runOnObject($hash, ObjectType::Commit, 'log', ['--encoding=UTF-8', '--format=raw', '--no-color', '-n', '1', $hash, '--']));

        $commit = $parser->commits[0] ?? null;
        // git log peels a tag to its commit.
        if (null === $commit || !str_starts_with($commit->hash, $hash)) {
            throw new ReferenceNotFoundException(\sprintf('The object "%s" is not a commit.', $hash));
        }

        return $commit;
    }

    public function getCommitLog(?array $includes, array $excludes, array $paths, int $offset, ?int $limit): array
    {
        LeadingDash::assertNone(...$includes ?? [], ...$excludes);

        if ([] === $includes) {
            return [];
        }

        // Re-encodes the commits whose "encoding" header isn't UTF-8.
        $args = ['--encoding=UTF-8', '--format=raw', '--no-color', '--skip='.$offset];

        if (null !== $limit) {
            $args[] = '-n';
            $args[] = (string) $limit;
        }

        $parser = new LogParser();
        $parser->parse($this->runOnRevisions('log', $args, $includes, $excludes, $paths));

        return $parser->commits;
    }

    public function countCommits(?array $includes, array $excludes, array $paths): int
    {
        LeadingDash::assertNone(...$includes ?? [], ...$excludes);

        if ([] === $includes) {
            return 0;
        }

        return (int) $this->runOnRevisions('rev-list', ['--count'], $includes, $excludes, $paths);
    }

    public function getMergeBases(string $hash1, string $hash2): array
    {
        LeadingDash::assertNone($hash1, $hash2);

        $process = $this->execute('merge-base', ['--all', $hash1, $hash2]);

        // git exits with 1 and no output when there is no common ancestor; any
        // other failure (unknown revision, corrupt repository...) is an error.
        if (1 === $process->getExitCode() && '' === $process->getOutput()) {
            return [];
        }

        if (!$process->isSuccessful()) {
            throw new ProcessException($process, 'merge-base');
        }

        return explode("\n", trim($process->getOutput()));
    }

    public function getTreeEntries(string $hash): array
    {
        $parser = new TreeParser();
        $parser->parse($this->readObject($hash, ObjectType::Tree));

        return $parser->entries;
    }

    public function getBlobContent(string $hash): string
    {
        return $this->readObject($hash, ObjectType::Blob);
    }

    public function getTagData(string $hash): TagData
    {
        $parser = new TagParser();
        $parser->parse($this->readObject($hash, ObjectType::Tag));

        return $parser->data;
    }

    public function getReferences(): array
    {
        // Unlike for-each-ref in git 1.x, show-ref skips a dangling symbolic reference.
        $process = $this->execute('show-ref', ['--dereference']);

        // git exits with 1 and no output when there is no reference.
        if (1 === $process->getExitCode() && '' === $process->getOutput()) {
            return [];
        }

        if (!$process->isSuccessful()) {
            throw new ProcessException($process, 'show-ref');
        }

        $parser = new ReferenceParser();
        $parser->parse($process->getOutput());

        return $parser->references;
    }

    public function createReference(string $fullname, string $hash): void
    {
        LeadingDash::assertNone($fullname, $hash);
        ReferenceName::assertUnderRefs($fullname);

        // Zeros as the old value: git refuses to update a reference that exists.
        $this->run('update-ref', ['--no-deref', $fullname, $hash, str_repeat('0', 40)]);
    }

    public function updateReference(string $fullname, string $hash): void
    {
        LeadingDash::assertNone($fullname, $hash);
        ReferenceName::assertUnderRefs($fullname);

        // --no-deref: a symbolic reference, like refs/remotes/origin/HEAD, is
        // written itself, instead of the reference it points to.
        $this->run('update-ref', ['--no-deref', $fullname, $hash]);
    }

    public function deleteReference(string $fullname): void
    {
        LeadingDash::assertNone($fullname);
        ReferenceName::assertUnderRefs($fullname);

        $this->run('update-ref', ['--no-deref', '-d', $fullname]);
    }

    public function getIncludingBranches(string $hash): array
    {
        LeadingDash::assertNone($hash);

        // for-each-ref only accepts --contains since git 2.7.
        return [
            ...$this->listBranches(['--contains', $hash], 'refs/heads/'),
            ...$this->listBranches(['--remotes', '--contains', $hash], 'refs/remotes/'),
        ];
    }

    public function getDiff(?string $from, string $to): DiffData
    {
        LeadingDash::assertNone($from ?? '', $to);

        return $this->diff('diff-tree', [$from ?? self::EMPTY_TREE, $to]);
    }

    public function getWorkingTreeDiff(string $hash): DiffData
    {
        LeadingDash::assertNone($hash);

        return $this->diff('diff', [$hash]);
    }

    public function getDiffPending(): DiffData
    {
        return $this->diff('diff', []);
    }

    public function getDiffStaged(): DiffData
    {
        return $this->diff('diff', ['--staged']);
    }

    public function getBlameLines(string $hash, string $path, ?int $firstLine, ?int $lastLine): array
    {
        LeadingDash::assertNone($hash);

        // --no-textconv: blame reads the files through their textconv filter by default.
        $args = ['-p', '--no-textconv'];
        if (null !== $firstLine || null !== $lastLine) {
            $args[] = '-L';
            $args[] = ($firstLine ?? 1).','.$lastLine;
        }

        $parser = new BlameParser();
        $parser->parse($this->run('blame', [...$args, $hash, '--', $path]));

        return $parser->lines;
    }

    public function getUntrackedFiles(): array
    {
        $files = [];
        foreach (explode("\0", $this->run('status', ['--porcelain', '--untracked-files=all', '-z'])) as $entry) {
            if (str_starts_with($entry, '?? ')) {
                $files[] = substr($entry, 3);
            }
        }

        return $files;
    }

    public function checkout(string $revision): void
    {
        LeadingDash::assertNone($revision);

        // --: without it, git restores the file $revision names, when no
        // revision has this name, discarding its changes.
        $this->run('checkout', [$revision, '--']);
    }

    public function checkoutNewBranch(string $branch, string $startPoint): void
    {
        LeadingDash::assertNone($branch, $startPoint);

        // The branch, then its files: "checkout -b" checks the files of an
        // ambiguous $startPoint out before refusing it, and leaves them.
        // CONFIG sets the upstream branch, like git's default: --track or
        // --no-track would need to know whether $startPoint is a remote branch.
        $this->run('branch', [$branch, $startPoint]);

        try {
            $this->checkout($branch);
        } catch (DriverException $e) {
            // Like "checkout -b", which creates nothing when the checkout
            // fails. The configuration of the branch goes with it.
            $this->run('branch', ['-D', $branch]);

            throw $e;
        }
    }

    public function merge(string $revision, FastForward $fastForward): void
    {
        LeadingDash::assertNone($revision);

        $option = match ($fastForward) {
            FastForward::Allow => '--ff',
            FastForward::Never => '--no-ff',
            FastForward::Only => '--ff-only',
        };

        $this->run('merge', [$option, $revision]);
    }

    /**
     * Runs any git command, for what no driver method covers.
     *
     * @param array<int|string, int|string> $args
     * @param string|null                   $input The standard input of git
     *
     * @throws ProcessException The command failed
     */
    public function runProcess(string $command, #[\SensitiveParameter] array $args = [], ?string $input = null): Process
    {
        $process = $this->execute($command, array_map(strval(...), $args), $input);

        if (!$process->isSuccessful()) {
            throw new ProcessException($process, $command);
        }

        return $process;
    }

    /**
     * @param string[] $args
     *
     * @throws ProcessException
     */
    private function run(string $command, array $args): string
    {
        return $this->runProcess($command, $args)->getOutput();
    }

    /**
     * @param string[] $args
     */
    private function diff(string $command, array $args): DiffData
    {
        // --: a file of the working tree may have the name of a revision.
        $output = $this->run($command, [...self::DIFF_ARGS, ...$args, '--']);

        $parser = new DiffParser();
        $parser->parse($output);

        return new DiffData($parser->files, $output);
    }

    /**
     * Reads the content of the object $hash, in the format git stores it.
     *
     * Unlike ``git cat-file <type>`` or ``git ls-tree``, refuses an object
     * of another type, instead of reading the tree of a commit for instance.
     *
     * @throws ReferenceNotFoundException $hash doesn't exist, or isn't of the type $type
     */
    private function readObject(string $hash, ObjectType $type): string
    {
        $output = $this->runProcess('cat-file', ['--batch'], $hash."\n")->getOutput();

        // "<hash> <type> <size>", or "<hash> missing".
        $header = explode(' ', (string) strstr($output, "\n", true));
        if (3 !== \count($header)) {
            throw new ReferenceNotFoundException(\sprintf('The object "%s" does not exist.', $hash));
        }
        if ($type->value !== $header[1]) {
            throw new ReferenceNotFoundException(\sprintf('The object "%s" is not a %s.', $hash, $type->value));
        }

        return substr($output, \strlen(implode(' ', $header)) + 1, (int) $header[2]);
    }

    /**
     * Runs a command reading the object $hash.
     *
     * @param ObjectType|null $type The type $hash must have, null for any
     * @param string[]        $args
     *
     * @throws ReferenceNotFoundException $hash doesn't exist, or isn't of the type $type
     */
    private function runOnObject(string $hash, ?ObjectType $type, string $command, array $args): string
    {
        try {
            return $this->run($command, $args);
        } catch (ProcessException $e) {
            // Only checked when the command fails: one more process.
            $process = $this->execute('cat-file', ['-t', $hash]);
            if (!$process->isSuccessful()) {
                throw new ReferenceNotFoundException(\sprintf('The object "%s" does not exist.', $hash), 0, $e);
            }
            if (null !== $type && $type->value !== trim($process->getOutput())) {
                throw new ReferenceNotFoundException(\sprintf('The object "%s" is not a %s.', $hash, $type->value), 0, $e);
            }

            throw $e;
        }
    }

    /**
     * Runs $command on the commits reachable from $includes, but not from
     * $excludes.
     *
     * The hashes go through the standard input: thousands of them would
     * exceed the limit of the command line.
     *
     * @param string[]          $args     Arguments of $command
     * @param list<string>|null $includes Null for every reference and HEAD
     * @param list<string>      $excludes
     * @param string[]          $paths
     */
    private function runOnRevisions(string $command, array $args, ?array $includes, array $excludes, array $paths): string
    {
        // A hash per line, "^" excluding it. The standard input takes no option, like "--all".
        $input = '';
        foreach ([...$includes ?? [], ...array_map(static fn (string $hash): string => '^'.$hash, $excludes)] as $revision) {
            $input .= $revision."\n";
        }

        return $this->runProcess($command, [...$args, ...null === $includes ? ['--all'] : [], '--stdin', '--', ...$paths], $input)->getOutput();
    }

    /**
     * @param string[] $args Arguments of ``git branch``
     *
     * @return string[] The full names of the branches git lists
     */
    private function listBranches(array $args, string $prefix): array
    {
        $branches = [];
        foreach (explode("\n", rtrim($this->run('branch', ['--no-color', '--no-column', ...$args]), "\n")) as $line) {
            // "* " flags the current branch, "(HEAD detached at ...)" a detached
            // HEAD, and "origin/HEAD -> origin/main" a symbolic reference.
            $name = substr($line, 2);
            if ('' === $name || str_starts_with($name, '(') || str_contains($name, ' -> ')) {
                continue;
            }

            $branches[] = $prefix.$name;
        }

        return $branches;
    }

    /**
     * @param string[] $args
     */
    private function execute(string $command, #[\SensitiveParameter] array $args, ?string $input = null): Process
    {
        // --no-replace-objects: like libgit2, gitlib reads the objects as they
        // are, whatever "git replace" made of them in refs/replace/.
        $globalOptions = [...self::CONFIG, '--git-dir', $this->gitDir, '--no-replace-objects'];
        if (null !== $this->workingDir) {
            $globalOptions = [...$globalOptions, '--work-tree', $this->workingDir];
        }

        // Relative paths, in arguments as in pathspecs, resolve against the repository.
        return $this->runner->run($command, $args, $globalOptions, $this->workingDir ?? $this->gitDir, $input);
    }
}
