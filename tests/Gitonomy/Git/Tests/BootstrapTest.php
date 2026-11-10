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
                // Without GIT_ENVS, which the bootstrap sets on Windows.
                $variables[$source] = array_filter($values, static fn (string $name): bool => str_starts_with($name, 'GIT_') && 'GIT_ENVS' !== $name, \ARRAY_FILTER_USE_KEY);
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

    /**
     * gpg, which git runs to check the signature of a commit, creates its home when it is missing.
     */
    public function testTheBootstrapKeepsGpgOutOfTheWorkingTree(): void
    {
        $gnupgHome = getenv('GNUPGHOME');

        $this->assertIsString($gnupgHome);
        $this->assertSame($gnupgHome, $_SERVER['GNUPGHOME'] ?? null, 'Symfony Process passes it on to git');
        $this->assertDirectoryExists($gnupgHome);
        $this->assertStringStartsNotWith(\dirname(self::BOOTSTRAP), $gnupgHome, 'Out of tests/, where HOME is');
    }

    /**
     * A typo in GITLIB_DRIVER must not run, and pass, the tests of CliDriver.
     */
    public function testAnUnknownDriverIsRefused(): void
    {
        $driver = $_SERVER['GITLIB_DRIVER'] ?? null;
        $_SERVER['GITLIB_DRIVER'] = 'libgit2';

        try {
            self::getDriver();
            $this->fail('GITLIB_DRIVER is "cli" or "ffi".');
        } catch (\UnexpectedValueException $e) {
            $this->assertSame('The GITLIB_DRIVER environment variable must be "cli" or "ffi", "libgit2" given.', $e->getMessage());
        } finally {
            if (null === $driver) {
                unset($_SERVER['GITLIB_DRIVER']);
            } else {
                $_SERVER['GITLIB_DRIVER'] = $driver;
            }
        }
    }
}
