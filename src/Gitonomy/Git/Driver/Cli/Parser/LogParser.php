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

namespace Gitonomy\Git\Driver\Cli\Parser;

use Gitonomy\Git\Driver\Data\CommitData;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Parser\ParserBase;

/**
 * Parses the output of ``git log --format=raw``.
 *
 * @internal Used by CliDriver only
 */
final class LogParser extends ParserBase
{
    /**
     * @var list<CommitData>
     */
    public array $commits = [];

    /**
     * @param array<string, true> $shallowHashes The commits a shallow clone cuts the history at, as keys
     */
    public function __construct(
        private readonly array $shallowHashes,
    ) {
    }

    protected function doParse(): void
    {
        $this->commits = [];

        while (!$this->isFinished()) {
            $this->consume('commit ');
            $hash = $this->consumeHash();
            $this->consumeNewLine();

            $treeHash = null;
            $parentHashes = [];
            $author = null;
            $committer = null;
            // Like git, which reads the tree then the parents on the first
            // lines only: a forged commit may have these headers again below,
            // which git ignores. The last author and committer are the ones
            // "git log" shows.
            $atTop = true;
            // The headers end with an empty line, unless the commit is the
            // last one and has no message. The lines following a header, of
            // a signature (gpgsig) or a merged tag (mergetag), start with a
            // space.
            while (!$this->isFinished() && !$this->expects("\n")) {
                [$name, $value] = explode(' ', $this->consumeTo("\n"), 2) + [1 => ''];
                $this->consumeNewLine();
                while ($this->expects(' ')) {
                    $this->consumeTo("\n");
                    $this->consumeNewLine();
                }

                if ($atTop && 'tree' === $name && null === $treeHash) {
                    $treeHash = $value;

                    continue;
                }
                if ($atTop && 'parent' === $name && null !== $treeHash) {
                    $parentHashes[] = $value;

                    continue;
                }

                $atTop = false;
                match ($name) {
                    'author' => $author = self::parseSignature($value),
                    'committer' => $committer = self::parseSignature($value),
                    // encoding, gpgsig, mergetag, and the headers of other tools.
                    default => null,
                };
            }

            if (null === $treeHash || null === $author || null === $committer) {
                throw new RuntimeException(\sprintf('The commit "%s" lacks a tree, an author or a committer.', $hash));
            }

            // git indents every line of the message.
            $message = '';
            while ($this->expects('    ')) {
                $message .= $this->consumeTo("\n")."\n";
                $this->consumeNewLine();
            }

            // An empty line separates the commits.
            $this->expects("\n");

            // git reads a shallow commit as a root commit, though it prints its "parent" headers.
            $this->commits[] = new CommitData($hash, $treeHash, isset($this->shallowHashes[$hash]) ? [] : $parentHashes, ...$author, ...$committer, message: $message);
        }
    }
}
