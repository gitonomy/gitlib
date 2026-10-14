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

namespace Gitonomy\Git\Parser;

use Gitonomy\Git\Exception\RuntimeException;

abstract class ParserBase
{
    protected int $cursor;
    protected string $content;
    protected int $length;

    public function parse(string $content): void
    {
        $this->cursor = 0;
        $this->content = $content;
        $this->length = \strlen($this->content);

        $this->doParse();
    }

    abstract protected function doParse(): void;

    protected function isFinished(): bool
    {
        return $this->cursor === $this->length;
    }

    protected function consumeAll(): string
    {
        $rest = substr($this->content, $this->cursor);
        $this->cursor += \strlen($rest);

        return $rest;
    }

    /**
     * Consumes $expected if the content continues with it.
     *
     * @phpstan-impure
     */
    protected function expects(string $expected): bool
    {
        $length = \strlen($expected);
        $actual = substr($this->content, $this->cursor, $length);
        if ($actual !== $expected) {
            return false;
        }

        $this->cursor += $length;

        return true;
    }

    protected function consumeShortHash(): string
    {
        if (!preg_match('/([A-Za-z0-9]{7,40})/A', $this->content, $vars, 0, $this->cursor)) {
            throw new RuntimeException(\sprintf('Expected a hash, got "%s".', substr($this->content, $this->cursor, 7)));
        }

        $this->cursor += \strlen($vars[1]);

        return $vars[1];
    }

    protected function consumeHash(): string
    {
        if (!preg_match('/([A-Za-z0-9]{40})/A', $this->content, $vars, 0, $this->cursor)) {
            throw new RuntimeException(\sprintf('Expected a hash, got "%s".', substr($this->content, $this->cursor, 40)));
        }

        $this->cursor += 40;

        return $vars[1];
    }

    protected function consumeRegexp(string $regexp): array
    {
        if (!preg_match($regexp.'A', $this->content, $vars, 0, $this->cursor)) {
            throw new RuntimeException(\sprintf('Expected %s, got "%s".', $regexp, substr($this->content, $this->cursor, 500)));
        }

        $this->cursor += \strlen($vars[0]);

        return $vars;
    }

    protected function consumeTo(string $text): string
    {
        $pos = strpos($this->content, $text, $this->cursor);

        if (false === $pos) {
            throw new RuntimeException(\sprintf('Expected "%s" after "%s".', $text, substr($this->content, $this->cursor, 100)));
        }

        $result = substr($this->content, $this->cursor, $pos - $this->cursor);
        $this->cursor = $pos;

        return $result;
    }

    protected function consume(string $expected): string
    {
        $length = \strlen($expected);
        $actual = substr($this->content, $this->cursor, $length);
        if ($actual !== $expected) {
            throw new RuntimeException(\sprintf('Expected "%s", got "%s".', $expected, substr($this->content, $this->cursor, 10)));
        }
        $this->cursor += $length;

        return $expected;
    }

    protected function consumeNewLine(): string
    {
        return $this->consume("\n");
    }

    /**
     * @return array{string, string, \DateTimeImmutable} The name, email and date of an author, committer or tagger
     */
    protected function consumeNameEmailDate(): array
    {
        if (!preg_match('/(([^\n]*) <([^\n]*)> (\d+) [+-]\d{4})/A', $this->content, $vars, 0, $this->cursor)) {
            throw new RuntimeException('Unable to parse name, email and date.');
        }

        $this->cursor += \strlen($vars[1]);

        return [$vars[2], $vars[3], self::timestamp($vars[4])];
    }

    /**
     * @param string $timestamp Seconds since the epoch, as git writes them
     *
     * @throws RuntimeException The date is out of range, in a corrupt object
     */
    protected static function timestamp(string $timestamp): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable('@'.$timestamp)->setTimezone(new \DateTimeZone('UTC'));
        } catch (\DateMalformedStringException $e) {
            throw new RuntimeException(\sprintf('The date "%s" is out of range.', $timestamp), 0, $e);
        }
    }
}
