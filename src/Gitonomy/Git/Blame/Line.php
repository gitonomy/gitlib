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

namespace Gitonomy\Git\Blame;

use Gitonomy\Git\Commit;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
final readonly class Line
{
    /**
     * @param int $number         The number of the line in the blamed file
     * @param int $originalNumber The number of the line in the file of $commit
     */
    public function __construct(
        private Commit $commit,
        private int $number,
        private int $originalNumber,
        private string $content,
    ) {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @return int The number of the line in the blamed file
     */
    public function getNumber(): int
    {
        return $this->number;
    }

    /**
     * @return int The number of the line in the file of the commit that last changed it
     */
    public function getOriginalNumber(): int
    {
        return $this->originalNumber;
    }

    /**
     * @deprecated since gitonomy/gitlib 2.0, use getOriginalNumber() instead
     */
    public function getLine(): string
    {
        trigger_deprecation('gitonomy/gitlib', '2.0', 'The "%s()" method is deprecated, use "getOriginalNumber()" instead.', __METHOD__);

        return (string) $this->originalNumber;
    }

    /**
     * @return Commit The commit that last changed the line
     */
    public function getCommit(): Commit
    {
        return $this->commit;
    }
}
