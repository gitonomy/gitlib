<?php

/**
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the GPL license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gitonomy\Git;

/**
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class RemoteBag implements \IteratorAggregate
{
    protected $repository;

    protected $remotes;

    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
    }

    public function getAll()
    {
        if (null !== $this->remotes) {
            return $this->remotes;
        }

        $read = array();
        $output = $this->repository->run('remote', array('-v'));
        $exp = explode("\n", $output);
        foreach ($exp as $i => $line) {
            if ($line == "") {
                continue;
            }

            if (!preg_match('/(?P<name>[^\s]+)\s(?P<url>[^\s]+)\s\((?P<type>\w+)\)$/', $line, $vars)) {
                throw new \RuntimeException(sprintf('Unexpected git remote output. Expected "name url (type)", got "%s" at line %s :'."\n".$output, $line, $i + 1));
            }

            $name = $vars['name'];
            $type = $vars['type'];
            $url  = $vars['url'];
            $read[$name][$type] = $url;
        }

        foreach ($read as $name => $urls) {
            $this->remotes[$name] = new Remote($this->repository, $name, $urls);
        }

        return $this->remotes;
    }

    public function fetch($name = null)
    {
        if (null === $name) {
            $args = array('--all');
        } else {
            $args = array($name);
        }

        $this->repository->run('fetch', $args);

        return $this;
    }

    public function getIterator()
    {
        return new \ArrayIterator($this->getAll());
    }
}
