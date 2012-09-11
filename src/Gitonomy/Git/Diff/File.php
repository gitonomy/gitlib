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

namespace Gitonomy\Git\Diff;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class File
{
    /**
     * @var string
     */
    protected $oldName;

    /**
     * @var string
     */
    protected $newName;

    /**
     * @var string
     */
    protected $oldMode;

    /**
     * @var string
     */
    protected $newMode;

    /**
     * @var array An array of FileChange objects
     */
    protected $changes;

    /**
     * @var boolean
     */
    protected $isBinary;

    /**
     * Instanciates a new Diff File object.
     */
    public function __construct($oldName, $newName, $oldMode, $newMode, $isBinary)
    {
        $this->oldName = $oldName;
        $this->newName = $newName;
        $this->oldMode = $oldMode;
        $this->newMode = $newMode;
        $this->changes = array();
        $this->isBinary = $isBinary;
    }

    /**
     * Indicates if this diff file is a creation.
     *
     * @return boolean
     */
    public function isCreation()
    {
        return null === $this->oldName;
    }

    /**
     * Indicates if this diff file is a creation.
     *
     * @return boolean
     */
    public function isModification()
    {
        return null !== $this->oldName && null !== $this->newName;
    }

    /**
     * Indicates if this diff file is a deletion.
     *
     * @return boolean
     */
    public function isDeletion()
    {
        return null === $this->newName;
    }

    /**
     * Indicates if this diff file is a deletion.
     *
     * @return boolean
     */
    public function isDelete()
    {
        return null === $this->newName;
    }

    /**
     * @return int Number of added lines
     */
    public function getAdditions()
    {
        $result = 0;
        foreach ($this->changes as $change) {
            $result += $change->getCount(FileChange::LINE_ADD);
        }

        return $result;
    }

    /**
     * @return int Number of deleted lines
     */
    public function getDeletions()
    {
        $result = 0;
        foreach ($this->changes as $change) {
            $result += $change->getCount(FileChange::LINE_REMOVE);
        }

        return $result;
    }

    public function addChange(FileChange $change)
    {
        $this->changes[] = $change;
    }

    public function getOldName()
    {
        return $this->oldName;
    }

    public function getNewName()
    {
        return $this->newName;
    }

    public function getOldMode()
    {
        return $this->oldMode;
    }

    public function getNewMode()
    {
        return $this->newMode;
    }

    public function getChanges()
    {
        return $this->changes;
    }

    public function isBinary()
    {
        return $this->isBinary;
    }

}
