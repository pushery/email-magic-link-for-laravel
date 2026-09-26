<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * Serializes an event without its request.
 *
 * A listener that implements ShouldQueue receives the event through the queue, and the
 * request of a booted application carries resolver closures that cannot be serialized.
 * Dispatching the event then failed before any queue saw it. The request endpoint
 * dispatches only for an address that resolves to an account, so that failure was a 500
 * for known addresses and a redirect for unknown ones: an answer to "does this account
 * exist".
 *
 * Serialized, the event keeps everything but the request, which a queued listener finds
 * set to null. A synchronous listener still receives it.
 *
 * It lives here rather than beside the events, because everything in the events namespace
 * is a final readonly class by an architecture rule, and a trait is neither.
 */
trait LeavesTheRequestBehind
{
    /**
     * @return array<mixed>
     */
    public function __serialize(): array
    {
        $properties = get_object_vars($this);

        unset($properties['request']);

        return $properties;
    }

    /**
     * @param  array<mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        foreach ($data as $property => $value) {
            if (is_string($property)) {
                $this->{$property} = $value;
            }
        }

        $this->request = null;
    }
}
