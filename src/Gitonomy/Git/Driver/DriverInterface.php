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

namespace Gitonomy\Git\Driver;

use Gitonomy\Git\Driver\Cli\CliDriver;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\UnsupportedOperationException;

/**
 * A configured git backend: opens repositories, and runs the operations that
 * need no repository yet.
 *
 * Pass one to Repository, which uses {@see CliDriver} by default, to
 * configure the backend, or to replace it in tests.
 *
 * Every method throws a {@see DriverException} when git reports a failure,
 * an {@see UnsupportedOperationException} when the driver can't perform the
 * operation at all, like LibGit2Driver cloning a bundle, and an
 * {@see InvalidArgumentException} when an argument contains a NUL byte, which
 * git doesn't accept.
 *
 * An application may get the URL of a repository from its users: every method
 * taking one refuses, with an {@see InvalidArgumentException}, a URL git would
 * misread, before running git. A URL starting with a dash is an option for
 * git, an "ext::" URL runs its command, and an ssh host or user starting with
 * a dash is an option for ssh.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
interface DriverInterface
{
    /**
     * Opens the repository in $dir: a bare repository, or a working tree with
     * its ".git" directory or file (a linked worktree, a submodule...).
     *
     * @param string|null $workingDir The working tree, when $dir is a git directory kept apart from it
     *
     * @throws InvalidArgumentException      $dir doesn't exist, or isn't a repository
     * @throws DriverException               The driver can't open the repository
     * @throws UnsupportedOperationException The repository uses SHA-256 hashes, or a format the driver can't read, like reftable for LibGit2Driver
     */
    public function open(string $dir, ?string $workingDir): RepositoryDriverInterface;

    /**
     * @throws DriverException
     */
    public function init(string $path): void;

    /**
     * @throws DriverException
     */
    public function initBare(string $path): void;

    /**
     * Clones $url with a working directory in $path, checking out $branch,
     * or the default branch of $url when null; like git, a tag named $branch
     * is checked out with HEAD detached, when $url has no such branch.
     *
     * @throws InvalidArgumentException      $url is a URL git would misread
     * @throws DriverException               git fails to clone $url
     * @throws UnsupportedOperationException The driver can't clone $url, like LibGit2Driver a bundle
     */
    public function cloneFrom(string $url, string $path, ?string $branch): void;

    /**
     * Clones the branches and the tags of $url into a bare repository in
     * $path, with HEAD pointing to $branch, or to the default branch of $url
     * when null; like git, HEAD is detached at a tag named $branch, when $url
     * has no such branch.
     *
     * @throws InvalidArgumentException      $url is a URL git would misread
     * @throws DriverException               git fails to clone $url
     * @throws UnsupportedOperationException The driver can't clone $url, like LibGit2Driver a bundle
     */
    public function cloneBareFrom(string $url, string $path, ?string $branch): void;

    /**
     * Clones every reference of $url into a bare repository in $path, set up
     * to mirror $url when fetching.
     *
     * @throws InvalidArgumentException      $url is a URL git would misread
     * @throws DriverException               git fails to clone $url
     * @throws UnsupportedOperationException The driver can't clone $url, like LibGit2Driver a bundle
     */
    public function mirrorFrom(string $url, string $path): void;

    /**
     * @param string $url A URL, or the path of a repository: never the name of a remote
     *
     * @return bool Whether $url can be reached
     *
     * @throws InvalidArgumentException $url is a URL git would misread
     */
    public function isValidRepository(string $url): bool;

    /**
     * @param string $url    Same as {@see isValidRepository()}
     * @param string $branch The name of the branch, exactly: "release" doesn't name "feature/release"
     *
     * @throws InvalidArgumentException $url is a URL git would misread
     */
    public function isValidRepositoryAndBranch(string $url, string $branch): bool;
}
