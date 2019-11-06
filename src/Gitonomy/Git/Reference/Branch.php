<?php

/**
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
class Branch extends Reference
{
    private $local = null;

    public function getName()
    {
        $fullname = $this->getFullname();

        if (preg_match('#^refs/heads/(?<name>.*)$#', $fullname, $vars)) {
            return $vars['name'];
        }

        if (preg_match('#^refs/remotes/(?<remote>[^/]*)/(?<name>.*)$#', $fullname, $vars)) {
            return $vars['remote'].'/'.$vars['name'];
        }

        throw new RuntimeException(sprintf('Cannot extract branch name from "%s"', $fullname));
    }

    public function isRemote()
    {
        $this->detectBranchType();

        return !$this->local;
    }

    public function isLocal()
    {
        $this->detectBranchType();

        return $this->local;
    }

	/**
	 *
	 * Check if this branch is merged to a destination branch
	 * Optionally, check only with remote branches
	 *
	 * @param string $destinationBranchName
	 * @param bool   $compareOnlyWithRemote
	 *
	 * @return null|bool
	 */
	public function isMergedTo($destinationBranchName = 'master', $compareOnlyWithRemote = false)
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
			return null;
		}

		if (!$result) {
			return null;
		}

		$output = explode("\n", trim(str_replace(['*', 'remotes/'], '', $result)));
		$output = array_filter($output, function ($v) {
			return false === StringHelper::strpos($v, '->');
		});
		$output = array_map('trim', $output);

		return (in_array($this->getName(), $output));
	}

    private function detectBranchType()
    {
        if (null === $this->local) {
            $this->local = !preg_match('#^refs/remotes/(?<remote>[^/]*)/(?<name>.*)$#', $this->getFullname());
        }
    }
}
