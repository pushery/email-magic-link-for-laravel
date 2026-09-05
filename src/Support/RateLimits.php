<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * Builds the named rate limiters for the package's throttled endpoints.
 *
 * The request endpoint is throttled per email and per IP; the consume endpoint
 * per IP and per token (keyed by the token hash, never the raw token); the
 * invitation display the same way as consume but out of its OWN budget.
 *
 * Every bucket prefix here is distinct (`eml:req:`, `eml:con:`, `eml:inv:`), and
 * that is the whole point of the third one: a shared prefix is a shared budget,
 * so viewing an invitation would spend the allowance accepting one needs -- and
 * behind one egress address, everyone else's too.
 */
final readonly class RateLimits
{
    public function __construct(
        private MagicLinkConfig $config,
        private Container $app,
    ) {}

    /**
     * Register every limiter this package names, and do it again on demand.
     *
     * Public and repeatable on purpose. Laravel keeps named limiters inside the
     * RateLimiter singleton, which captured its cache repository at boot; an
     * application that binds a cache per tenant has to discard that singleton so
     * it is rebuilt against the current one, and the named limiters go with it.
     * The application re-registers its own afterwards and needs a way to
     * re-register the package's -- calling this is the whole repair.
     *
     * It is also the reason the pairing lives here rather than in the service
     * provider: a consumer copying three RateLimiter::for() lines by hand silently
     * loses a fourth limiter added later, and finds out as a 500 in production.
     *
     * The limiter is resolved from the CONTAINER at call time, never through the
     * facade. The facade caches the first instance it resolved, so after the
     * `forgetInstance()` above it would register these limiters on the discarded
     * object -- while the throttle middleware, container-constructed, receives the
     * new and empty one, and every throttled route answers 500. Measured: the
     * repair the docblock describes only worked when the host ALSO cleared the
     * facade, which nothing told it to do.
     */
    public function define(): void
    {
        $limiter = $this->app->make(RateLimiter::class);

        $limiter->for($this->config->requestLimiter(), fn (Request $http): array => $this->forRequest($http));
        $limiter->for($this->config->consumeLimiter(), fn (Request $http): array => $this->forConsume($http));
        $limiter->for($this->config->invitationViewLimiter(), fn (Request $http): array => $this->forInvitationView($http));
    }

    /**
     * @return list<Limit>
     */
    public function forRequest(Request $request): array
    {
        $limit = $this->config->requestLimit();
        $email = $request->input('email');
        $email = is_string($email) ? NormalizedEmail::from($email) : '';

        return [
            // Hashed here rather than left to the framework, and the difference only shows on
            // a host that has turned the framework's hashing off. `RateLimiter::shouldHashKeys(false)`
            // is a supported call, and after it the raw key is what reaches the cache -- so the
            // address would sit in Redis in the clear, under a key that survives as long as the
            // window. This class already hashes the token and says so in a comment; the address
            // is the more identifying of the two, and it was going through raw.
            //
            // The IP is deliberately NOT hashed: it is not the subject, it is already in every
            // access log the request touches, and an operator reading a limiter key needs to be
            // able to recognize a hostile source.
            Limit::perMinutes($limit['per_minutes'], $limit['max'])->by('eml:req:email:'.hash('sha256', $email)),
            Limit::perMinutes($limit['per_minutes'], $limit['max'])->by('eml:req:ip:'.($request->ip())),
        ];
    }

    /**
     * @return list<Limit>
     */
    public function forConsume(Request $request): array
    {
        $limit = $this->config->consumeLimit();

        return [
            Limit::perMinutes($limit['per_minutes'], $limit['max'])->by('eml:con:ip:'.($request->ip())),
            Limit::perMinutes($limit['per_minutes'], $limit['max'])->by('eml:con:id:'.$this->discriminator($request)),
        ];
    }

    /**
     * The invitation display page. Same shape as consume, different budget.
     *
     * @return list<Limit>
     */
    public function forInvitationView(Request $request): array
    {
        $limit = $this->config->invitationViewLimit();

        return [
            Limit::perMinutes($limit['per_minutes'], $limit['max'])->by('eml:inv:ip:'.($request->ip())),
            Limit::perMinutes($limit['per_minutes'], $limit['max'])->by('eml:inv:id:'.$this->discriminator($request)),
        ];
    }

    /**
     * The token in the path decides the bucket; the submitted email is the fallback
     * only when there is no token, so a caller cannot pick a bucket by shortening the
     * token in the URL. Never the raw subject -- always its hash.
     *
     * That sentence said "never the raw TOKEN", and the fallback beneath it returned the
     * raw address. The asymmetry was invisible because the promise was written about the
     * branch that kept it. Both branches hash now, and the normalization still decides
     * the bucket -- it just happens before the hash rather than instead of it.
     */
    private function discriminator(Request $request): string
    {
        $token = $request->route('token');

        if (is_string($token) && $token !== '') {
            return hash('sha256', $token);
        }

        $email = $request->input('email');

        return hash('sha256', is_string($email) ? NormalizedEmail::from($email) : '');
    }
}
