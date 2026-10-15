<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * The source a request counts against on the limiters' per-source axis.
 *
 * An IPv4 address is one source as it is. An ordinary IPv6 connection is a whole /64, and a host
 * may send from any address in it, so the raw address let one host multiply the per-source axis
 * at will; an IPv6 address counts by its /64. An IPv4 address written as IPv6 (`::ffff:192.0.2.1`)
 * is that IPv4 address. A value that is no address, or no value at all, stays as it came.
 */
final class ClientSource
{
    public static function of(?string $ip): string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return (string) $ip;
        }

        $packed = (string) inet_pton($ip);

        if (strlen($packed) === 4) {
            return $ip;
        }

        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
