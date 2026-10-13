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
use Psr\Log\LoggerInterface;

/**
 * A configured git backend: opens repositories, and runs the operations that
 * need no repository yet.
 *
 * Pass one to Repository, which uses {@see CliDriver} by default, to
 * configure the backend, or to replace it in tests.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
interface DriverInterface
{
    /**
     * @param LoggerInterface|null $logger Receives the git commands the driver runs, if it runs any
     */
    public function open(string $gitDir, ?string $workingDir, ?LoggerInterface $logger): RepositoryDriverInterface;

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
     * or the default branch of $url when null.
     *
     * @throws DriverException
     */
    public function cloneFrom(string $url, string $path, ?string $branch): void;

    /**
     * Clones the branches and the tags of $url into a bare repository in
     * $path, with HEAD pointing to $branch, or to the default branch of $url
     * when null.
     *
     * @throws DriverException
     */
    public function cloneBareFrom(string $url, string $path, ?string $branch): void;

    /**
     * Clones every reference of $url into a bare repository in $path, set up
     * to mirror $url when fetching.
     *
     * @throws DriverException
     */
    public function mirrorFrom(string $url, string $path): void;

    public function isValidRepository(string $url): bool;

    public function isValidRepositoryAndBranch(string $url, string $branchName): bool;
}
