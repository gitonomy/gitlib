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

    /**
     * @return array<int|string, string> The matches, like the ones of preg_match()
     */
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
     *
     * @throws RuntimeException The line has no email between "<" and ">"
     */
    protected function consumeNameEmailDate(): array
    {
        return self::parseSignature($this->consumeTo("\n"));
    }

    /**
     * Reads "Name <email> 1700000000 +0200" like libgit2, which LibGit2Driver
     * reads it with, and git: old commits, imported from other tools, have
     * signatures git wouldn't write anymore, like "Name<email>", or no date.
     *
     * @return array{string, string, \DateTimeImmutable} The name, email and date, the epoch in UTC without one
     *
     * @throws RuntimeException $signature has no email between "<" and ">"
     */
    protected static function parseSignature(string $signature): array
    {
        $emailStart = strrpos($signature, '<');
        $emailEnd = strrpos($signature, '>');
        if (false === $emailStart || false === $emailEnd || $emailEnd < $emailStart) {
            throw new RuntimeException(\sprintf('Unable to parse the name, email and date of "%s".', $signature));
        }

        // The date, then the offset, are optional.
        preg_match('/^\s*(\d+)(?:\s+([+-]\d+))?/', substr($signature, $emailEnd + 1), $date);

        return [
            trim(substr($signature, 0, $emailStart)),
            trim(substr($signature, $emailStart + 1, $emailEnd - $emailStart - 1)),
            self::signatureDate($date[1] ?? '0', $date[2] ?? '+0000'),
        ];
    }

    /**
     * @param string $timestamp Seconds since the epoch, as git writes them
     * @param string $offset    The UTC offset of the author, committer or tagger, as git writes it: "+0200", "-0330"..., a sign then hours and minutes
     *
     * @return \DateTimeImmutable The date at $offset, like git log shows it
     *
     * @throws RuntimeException The date is out of range, in a corrupt object
     */
    protected static function signatureDate(string $timestamp, string $offset): \DateTimeImmutable
    {
        // Like libgit2, which LibGit2Driver reads dates with: an offset of
        // more than 14 hours or 59 minutes, which no timezone has, in a
        // corrupt object, gives UTC.
        $hoursAndMinutes = (int) substr($offset, 1);
        [$hours, $minutes] = [intdiv($hoursAndMinutes, 100), $hoursAndMinutes % 100];
        $timezone = $hours > 14 || $minutes > 59 ? '+0000' : \sprintf('%s%02d%02d', $offset[0], $hours, $minutes);

        try {
            return new \DateTimeImmutable('@'.$timestamp)->setTimezone(new \DateTimeZone($timezone));
        } catch (\DateMalformedStringException $e) {
            throw new RuntimeException(\sprintf('The date "%s" is out of range.', $timestamp), 0, $e);
        }
    }
}
