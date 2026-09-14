<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * How the WireKit code screen lays out its code boxes, from the code length alone.
 *
 * WireKit's otp-input wraps its boxes wherever the width runs out, so on the 24rem card an
 * eight-character code broke 6+2 on a desktop and 5+3 on a phone, at no boundary the code has.
 * The layout turns the box row into a grid instead: one row while it fits, and an even split
 * below that, halving again while a row still would not fit. The card widens for a long code, up
 * to a limit, so a desktop keeps the whole code in one row.
 *
 * The sizes are WireKit's: a box is `w-10` (2.5rem) and the gap `gap-2` (0.5rem).
 */
final readonly class CodeBoxLayout
{
    private const float BOX_REM = 2.5;

    private const float GAP_REM = 0.5;

    /** The shell's padding on both sides plus the card body's, with a little room to spare. */
    private const float CARD_ALLOWANCE_REM = 6.5;

    private const float CARD_MIN_REM = 24.0;

    private const float CARD_MAX_REM = 36.0;

    public function __construct(private int $length) {}

    /** The number of columns in the full row, never less than one. */
    public function columns(): int
    {
        return max(1, $this->length);
    }

    /** The width of one row of `$columns` boxes and the gaps between them, in rem. */
    public static function rowRem(int $columns): float
    {
        return $columns * self::BOX_REM + max(0, $columns - 1) * self::GAP_REM;
    }

    /** How wide the card may grow so a desktop keeps the whole code in one row, in rem. */
    public function cardMaxWidthRem(): float
    {
        return min(self::CARD_MAX_REM, max(self::CARD_MIN_REM, self::rowRem($this->columns()) + self::CARD_ALLOWANCE_REM));
    }

    /**
     * The column counts below the full row, each with the container width under which it applies.
     *
     * Every step halves the previous column count, rounding up, and applies below the width of a
     * row of the previous count. A narrower container matches every step above it, and the last
     * rule it matches wins.
     *
     * @return list<array{below: float, columns: int}>
     */
    public function splits(): array
    {
        $splits = [];

        for ($columns = $this->columns(); $columns > 1; $columns = $next) {
            $next = (int) ceil($columns / 2);
            $splits[] = ['below' => self::rowRem($columns), 'columns' => $next];
        }

        return $splits;
    }
}
