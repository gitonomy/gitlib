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

use Gitonomy\Git\Repository;

/**
 * The options of Repository and Admin configuring git in 1.x, deprecated in
 * favor of the arguments of CliDriver.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @internal To be removed in 3.0, with these options
 *
 * @phpstan-import-type Options from Repository
 */
final class LegacyOptions
{
    private const array NAMES = ['command', 'environment_variables', 'inherit_environment_variables', 'process_timeout'];

    /**
     * @param Options $options
     *
     * @return list<string> The names of the options configuring git in $options
     */
    public static function find(array $options): array
    {
        return array_values(array_intersect(array_keys($options), self::NAMES));
    }

    /**
     * @param Options $options
     *
     * @return Options $options without the options configuring git
     */
    public static function remove(array $options): array
    {
        foreach (self::NAMES as $name) {
            unset($options[$name]);
        }

        return $options;
    }

    /**
     * Creates the CliDriver the options of $options configure, with the
     * defaults of 1.x.
     *
     * @param Options $options
     */
    public static function createDriver(array $options): CliDriver
    {
        return new CliDriver(
            $options['command'] ?? 'git',
            $options['environment_variables'] ?? [],
            $options['inherit_environment_variables'] ?? false,
            // null disables the timeout.
            \array_key_exists('process_timeout', $options) ? $options['process_timeout'] : 3600,
        );
    }
}
