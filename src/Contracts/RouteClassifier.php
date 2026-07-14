<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Contracts;

use JkBennemann\LaravelApiDocumentation\Data\RouteInfo;

/**
 * Assigns a route to zero or more documentation output files.
 *
 * This is the fail-safe alternative to the URI blocklist (`excluded_routes`):
 * the application supplies a single, code-level source of truth that maps every
 * route to the spec files it belongs in. Returning an empty array excludes the
 * route from *every* file — so a route that is not explicitly classified is
 * invisible by construction rather than leaking by default.
 *
 * Configured via `api-documentation.route_classifier` (a class-string resolved
 * from the container, or an instance).
 */
interface RouteClassifier
{
    /**
     * @return string[]|null List of documentation file keys the route belongs to
     *                       (e.g. ['public', 'reseller']); an empty array to
     *                       exclude it from all files; or null to defer to the
     *                       package's default `#[DocumentationFile]` resolution.
     */
    public function classify(RouteInfo $route): ?array;
}
