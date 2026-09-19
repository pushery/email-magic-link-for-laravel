<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * How the WireKit code screen lays out its code boxes, from the code length alone.
 *
 * WireKit's otp-input used to wrap its boxes wherever the width ran out, so on the 24rem card an
 * eight-character code broke 6+2 on a desktop and 5+3 on a phone, at no boundary the code has.
 * This class held the repair: a grid reaching in through the component's own `role="group"`, one
 * row while it fits and an even split below that.
 *
 * That half is gone. WireKit 2.53 takes a `group` prop, the groups do not break inside, and a row
 * that does not fit breaks on a group boundary — the same result without a consumer patch over
 * somebody else's markup, and it moves with the component if that markup ever changes.
 *
 * What stays is the half that was never WireKit's business: how wide THIS package's card may grow
 * so a desktop keeps the whole code in one row. A card width is a decision about the screen around
 * the component, not about the component.
 *
 * The sizes are WireKit's: a box is `w-10` (2.5rem) and the gap `gap-2` (0.5rem).
 */
final readonly class CodeBoxLayout
{
    private const float BOX_REM = 2.5;

    private const float GAP_REM = 0.5;

    /**
     * The gap BETWEEN groups, which is wider than the gap between boxes.
     *
     * That width is the visible separator — WireKit chose a gap over a glyph so a screen reader has
     * nothing extra to read out or hide. It matters here because it makes a grouped row wider than
     * the same number of ungrouped boxes, and the card has to be sized for the row it will actually
     * paint. Missing that put an eight-box code at 4+4 on a 1280px desktop, half a rem short.
     */
    private const float GROUP_GAP_REM = 1.0;

    /** The shell's padding on both sides plus the card body's, with a little room to spare. */
    private const float CARD_ALLOWANCE_REM = 6.5;

    private const float CARD_MIN_REM = 24.0;

    private const float CARD_MAX_REM = 36.0;

    public function __construct(private int $length) {}

    /** The number of boxes in the full row, never less than one. */
    public function columns(): int
    {
        return max(1, $this->length);
    }

    /**
     * How many boxes form a group, for WireKit's `group` prop.
     *
     * Half the code, rounded up, which is the first step the old repair took and the only one a
     * group boundary can express: the groups themselves do not wrap, so `group` names ONE break
     * rather than a cascade. Measured against the widths the browser arms cover, the two agree —
     * eight boxes give 8 on a desktop and 4+4 at 375px and 320px, six give 6 and 3+3.
     *
     * The difference is at lengths nothing ships today: the old grid halved again and again while
     * a row still would not fit, so a twelve-character code in a very narrow container reached
     * three boxes per row where a group of six stops. That is a real loss of range, it is named
     * here rather than left to be discovered, and it costs nothing at the lengths that exist —
     * a group of six is 17.5rem, which fits the card this package widens for it.
     *
     * Null below THREE boxes, and the number is WireKit's rather than a preference. Its
     * StrictnessGate clamps `group` to the range 2..length, so half of a two-box code is 1 and
     * would be refused and silently replaced by the length — a prop that says something and does
     * nothing. Below three there is also nothing to say: half of two is a group per box, which
     * draws the separator gap between every character of a code the reader copies as one word.
     */
    public function group(): ?int
    {
        return $this->columns() < 3 ? null : (int) ceil($this->columns() / 2);
    }

    /** The width of one row of `$columns` boxes and the gaps between them, in rem. */
    public static function rowRem(int $columns): float
    {
        return $columns * self::BOX_REM + max(0, $columns - 1) * self::GAP_REM;
    }

    /**
     * The width of the row as it will actually be painted, in rem.
     *
     * Ungrouped this is `rowRem()`. Grouped, every boundary between two groups costs the difference
     * between the two gaps, so the row is wider than its box count alone suggests — measured, not
     * assumed: without this an eight-box code broke 4+4 on a 1280px desktop, half a rem short of
     * the room it needed.
     */
    public function paintedRowRem(): float
    {
        $group = $this->group();

        if ($group === null) {
            return self::rowRem($this->columns());
        }

        $boundaries = (int) ceil($this->columns() / $group) - 1;

        return self::rowRem($this->columns()) + $boundaries * (self::GROUP_GAP_REM - self::GAP_REM);
    }

    /** How wide the card may grow so a desktop keeps the whole code in one row, in rem. */
    public function cardMaxWidthRem(): float
    {
        return min(self::CARD_MAX_REM, max(self::CARD_MIN_REM, $this->paintedRowRem() + self::CARD_ALLOWANCE_REM));
    }
}
