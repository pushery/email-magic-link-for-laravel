<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Illuminate\Contracts\Translation\Loader;

/**
 * Translations of this package for a regional locale fall back to its language.
 *
 * Laravel's translator asks for the locale it was given and then for `app.fallback_locale`, nothing in between. The
 * package ships its catalogs per language, `pt` and `fr` without a region, so an app running `pt-BR` would get every
 * text of this package in the fallback language. This loader answers a regional locale of the package's namespace with
 * the language's lines, overlaid key by key by whatever the regional locale has of its own: a catalog an app publishes
 * under `lang/vendor/email-magic-link/pt-BR`, or one this package ships later. Any region works, including one nobody
 * listed: `fr-CA` reads `fr`, `de_AT` reads `de`.
 *
 * It wraps the loader the translator is built with and answers every other namespace exactly as that loader does, so
 * the app's own translations and every other package keep the lookup Laravel gives them. The translator takes its
 * loader when it is first resolved, which is after the providers registered in an ordinary boot; one resolved earlier
 * keeps the plain loader, and this fallback with it.
 */
final readonly class LanguageFallbackLoader implements Loader
{
    public function __construct(private Loader $inner, private string $namespace) {}

    /**
     * The language of a regional locale, lowercased: `pt` for `pt-BR` and for `pt_BR`. Null for a locale without a
     * region, which needs no fallback.
     */
    public static function languageOf(string $locale): ?string
    {
        return preg_match('/^([A-Za-z]{2,3})[-_][A-Za-z0-9]/', $locale, $parts) === 1 ? strtolower($parts[1]) : null;
    }

    /**
     * @param  string  $locale
     * @param  string  $group
     * @param  string|null  $namespace
     * @return array<array-key, mixed>
     */
    public function load($locale, $group, $namespace = null): array
    {
        $lines = $this->lines((string) $locale, (string) $group, $namespace);
        $language = self::languageOf((string) $locale);

        if ($namespace !== $this->namespace || $language === null) {
            return $lines;
        }

        return array_replace_recursive($this->lines($language, (string) $group, $namespace), $lines);
    }

    /**
     * @param  string  $namespace
     * @param  string  $hint
     */
    public function addNamespace($namespace, $hint): void
    {
        $this->inner->addNamespace($namespace, $hint);
    }

    /** @param  string  $path */
    public function addJsonPath($path): void
    {
        $this->inner->addJsonPath($path);
    }

    /** @return array<string, string> */
    public function namespaces(): array
    {
        return $this->inner->namespaces();
    }

    /**
     * The translator's own call for a path without a namespace, which the contract does not name and the file loader
     * answers. Forwarded like every other method the wrapped loader has beyond the contract.
     */
    public function addPath(string $path): void
    {
        $this->__call('addPath', [$path]);
    }

    /** @param  list<mixed>  $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }

    /** @return array<array-key, mixed> */
    private function lines(string $locale, string $group, ?string $namespace): array
    {
        return $this->inner->load($locale, $group, $namespace);
    }
}
