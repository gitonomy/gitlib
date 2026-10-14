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

namespace Gitonomy\Git\Tests;

use Symfony\Component\Process\Process;

/**
 * The tests run git in an environment of their own, whatever the developer's.
 */
class BootstrapTest extends AbstractTestCase
{
    private const string BOOTSTRAP = __DIR__.'/../../../bootstrap.php';

    public function testTheBootstrapRemovesTheVariablesOfGit(): void
    {
        // The variables git gives a hook, like pre-commit, running the tests.
        $dir = self::createTempDir();
        $environment = [
            'GIT_AUTHOR_NAME' => 'Hook',
            'GIT_COMMAND' => 'git',
            'GIT_CONFIG_NOSYSTEM' => '0',
            'GIT_DIR' => $dir.'/.git',
            'GIT_INDEX_FILE' => $dir.'/.git/index',
            'GIT_OBJECT_DIRECTORY' => $dir.'/.git/objects',
            'GIT_WORK_TREE' => $dir,
        ];

        $code = <<<'PHP'
            require $argv[1];
            $variables = [];
            foreach (['getenv' => getenv(), '_ENV' => $_ENV, '_SERVER' => $_SERVER] as $source => $values) {
                $variables[$source] = array_filter($values, static fn (string $name): bool => str_starts_with($name, 'GIT_'), \ARRAY_FILTER_USE_KEY);
                ksort($variables[$source]);
            }
            echo json_encode($variables);
            PHP;

        // With $_ENV, which php.ini usually leaves empty.
        $process = new Process([\PHP_BINARY, '-d', 'variables_order=EGPCS', '-r', $code, self::BOOTSTRAP], env: $environment);
        $process->mustRun();

        $expected = ['GIT_COMMAND' => 'git', 'GIT_CONFIG_NOSYSTEM' => '1'];
        $this->assertSame(['getenv' => $expected, '_ENV' => $expected, '_SERVER' => $expected], json_decode($process->getOutput(), true));
    }
}
