<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

/**
 * Builds the named rate limiters for the request and consume endpoints.
 *
 * The request endpoint is throttled per email and per IP; the consume endpoint
 * per IP and per token (keyed by the token hash, never the raw token).
 */
final readonly class RateLimits
{
    public function __construct(private MagicLinkConfig $config) {}

    /**
     * @return list<Limit>
     */
    public function forRequest(Request $request): array
    {
        $limit = $this->config->requestLimit();
        $email = $request->input('email');
        $email = is_string($email) ? mb_strtolower(trim($email)) : '';

        return [
            Limit::perMinutes($limit['per_minutes'], $limit['max'])->by('eml:req:email:'.$email),
            Limit::perMinutes($limit['per_minutes'], $limit['max'])->by('eml:req:ip:'.($request->ip())),
        ];
    }

    /**
     * @return list<Limit>
     */
    public function forConsume(Request $request): array
    {
        $limit = $this->config->consumeLimit();
        $token = $request->route('token');
        $email = $request->input('email');

        $discriminator = is_string($token) && $token !== ''
            ? hash('sha256', $token)
            : (is_string($email) ? mb_strtolower(trim($email)) : '');

        return [
            Limit::perMinutes($limit['per_minutes'], $limit['max'])->by('eml:con:ip:'.($request->ip())),
            Limit::perMinutes($limit['per_minutes'], $limit['max'])->by('eml:con:id:'.$discriminator),
        ];
    }
}
