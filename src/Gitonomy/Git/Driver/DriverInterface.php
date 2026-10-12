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
use Gitonomy\Git\Exception\UnsupportedOperationException;
use Psr\Log\LoggerInterface;

/**
 * A configured git backend: opens repositories, and runs the operations that
 * need no repository yet.
 *
 * Pass one to Repository and Admin, which use {@see CliDriver} by default,
 * to configure the backend, or to replace it in tests.
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
     * @param string[] $args Arguments of ``git clone``: ``--bare``, ``--mirror`` and ``--branch <name>`` are supported by every driver
     *
     * @throws DriverException
     * @throws UnsupportedOperationException An argument the driver doesn't support
     */
    public function cloneRepository(string $path, string $url, array $args): void;

    public function isValidRepository(string $url): bool;

    public function isValidRepositoryAndBranch(string $url, string $branchName): bool;
}
