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

use Gitonomy\Git\Exception\InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * The options of Repository and Admin in 1.x, deprecated in favor of the
 * "workingDir" argument of Repository, and of the arguments of CliDriver.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @internal To be removed in 3.0, with these options
 *
 * @phpstan-type Options array{command?: string, debug?: bool, environment_variables?: array<string, string|\Stringable|false>, inherit_environment_variables?: bool, logger?: LoggerInterface|null, process_timeout?: float|null, working_dir?: string|null}
 */
final readonly class LegacyOptions
{
    /**
     * The options configuring CliDriver.
     */
    private const array DRIVER_OPTIONS = ['command', 'environment_variables', 'inherit_environment_variables', 'logger', 'process_timeout'];

    public ?string $workingDir;

    /**
     * @var Options
     */
    private array $options;

    /**
     * @param Options $options
     *
     * @throws InvalidArgumentException An unknown option, or the "debug" one set to false
     */
    public function __construct(array $options)
    {
        if (\array_key_exists('debug', $options) && !$options['debug']) {
            throw new InvalidArgumentException('The "debug" option set to false is not supported anymore: a failing git operation always throws, catch its exception instead.');
        }

        $unknownOptions = array_diff(array_keys($options), [...self::DRIVER_OPTIONS, 'debug', 'working_dir']);
        if ([] !== $unknownOptions) {
            throw new InvalidArgumentException(\sprintf('Unknown options "%s".', implode('", "', $unknownOptions)));
        }

        // PHP doesn't check the types of the options, unlike PHPStan.
        if (null !== ($options['logger'] ?? null) && !$options['logger'] instanceof LoggerInterface) { // @phpstan-ignore instanceof.alwaysTrue, booleanAnd.alwaysFalse
            throw new InvalidArgumentException(\sprintf('The "logger" option must be a Psr\Log\LoggerInterface, "%s" given.', get_debug_type($options['logger'])));
        }

        $this->workingDir = $options['working_dir'] ?? null;
        $this->options = $options;
    }

    /**
     * The CliDriver running the git processes of a repository, null when no option configures one.
     *
     * Like in 1.x, "inherit_environment_variables" also gives them the entries of $_SERVER.
     */
    public function repositoryDriver(): ?CliDriver
    {
        $environmentVariables = $this->options['environment_variables'] ?? [];
        if ($this->options['inherit_environment_variables'] ?? false) {
            // $_SERVER also holds entries that aren't environment variables (argv, REQUEST_TIME...).
            $environmentVariables = array_replace(array_filter($_SERVER, \is_string(...)), $environmentVariables);
        }

        return $this->driver($environmentVariables);
    }

    /**
     * The CliDriver running the commands of Admin (init, clone, ls-remote), null when no option configures one.
     *
     * Like in 1.x, it ignores "inherit_environment_variables": these commands
     * reach remotes, and $_SERVER holds the credentials of an HTTP request.
     */
    public function adminDriver(): ?CliDriver
    {
        return $this->driver($this->options['environment_variables'] ?? []);
    }

    /**
     * @param array<string, string|\Stringable|false> $environmentVariables
     */
    private function driver(array $environmentVariables): ?CliDriver
    {
        if ([] === array_intersect(array_keys($this->options), self::DRIVER_OPTIONS)) {
            return null;
        }

        return new CliDriver(
            $this->options['command'] ?? 'git',
            $environmentVariables,
            // null disables the timeout.
            \array_key_exists('process_timeout', $this->options) ? $this->options['process_timeout'] : 3600,
            $this->options['logger'] ?? null,
        );
    }
}
