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

namespace Gitonomy\Git\Reference;

use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Reference;
use Gitonomy\Git\Util\StringHelper;

/**
 * Representation of a branch reference.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
final class Branch extends Reference
{
    private ?bool $local = null;

    public function getName(): string
    {
        $fullname = $this->getFullname();

        if (preg_match('#^refs/heads/(?<name>.*)$#', $fullname, $vars)) {
            return $vars['name'];
        }

        if (preg_match('#^refs/remotes/(?<remote>[^/]*)/(?<name>.*)$#', $fullname, $vars)) {
            return $vars['remote'].'/'.$vars['name'];
        }

        throw new RuntimeException(\sprintf('Cannot extract branch name from "%s"', $fullname));
    }

    public function isRemote(): bool
    {
        $this->detectBranchType();

        return !$this->local;
    }

    public function isLocal(): bool
    {
        $this->detectBranchType();

        return $this->local;
    }

    /**
     * Check if this branch is merged to a destination branch
     * Optionally, check only with remote branches.
     */
    public function isMergedTo(string $destinationBranchName = 'master', bool $compareOnlyWithRemote = false): bool
    {
        $arguments = ['-a'];

        if ($compareOnlyWithRemote) {
            $arguments = ['-r'];
        }

        $arguments[] = '--merged';
        $arguments[] = $destinationBranchName;

        try {
            $result = $this->repository->run('branch', $arguments);
        } catch (ProcessException $e) {
            throw new RuntimeException(\sprintf('Cannot determine if merged to the branch "%s"', $destinationBranchName), $e->getCode(), $e);
        }

        if (!$result) {
            return false;
        }

        $output = explode("\n", trim(str_replace(['*', 'remotes/'], '', $result)));
        $filtered_output = array_filter($output, static function ($v) {
            return false === StringHelper::strpos($v, '->');
        });
        $trimmed_output = array_map('trim', $filtered_output);

        return \in_array($this->getName(), $trimmed_output, true);
    }

    private function detectBranchType(): void
    {
        if (null === $this->local) {
            $this->local = !preg_match('#^refs/remotes/(?<remote>[^/]*)/(?<name>.*)$#', $this->getFullname());
        }
    }
}
