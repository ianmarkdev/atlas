<?php

namespace App\Domain\Applications;

/**
 * Compares job listing URLs: the same listing opened from an email, a search page or a share
 * link should match, while two listings that differ only in a query parameter (`?jk=…`,
 * `?currentJobId=…`) must not. So only known tracking parameters are dropped; every other
 * parameter is kept.
 */
class SourceUrl
{
    /**
     * Parameters that only record how the visitor arrived. Prefix matches end in `*`.
     */
    private const TRACKING_PARAMETERS = [
        'utm_*', 'gclid', 'gclsrc', 'dclid', 'fbclid', 'msclkid', 'igshid', 'mc_cid', 'mc_eid',
        '_hsenc', '_hsmi', 'trk', 'trkinfo', 'trackingid', 'refid', 'lipi',
    ];

    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * A comparison key for the URL, or null when it is not an absolute http(s) URL.
     * Scheme and host are lowercased, a leading `www.`, the default port, the fragment, a
     * trailing slash and tracking parameters are dropped, and the remaining parameters sorted.
     */
    public static function key(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $port = isset($parts['port']) && $parts['port'] !== self::DEFAULT_PORTS[$scheme] ? ':'.$parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');
        $query = self::canonicalQuery($parts['query'] ?? '');

        // http and https serve the same listing; the key ignores the difference.
        return $host.$port.$path.($query === '' ? '' : '?'.$query);
    }

    /**
     * Whether two URLs point at the same listing.
     */
    public static function same(string $a, string $b): bool
    {
        $key = self::key($a);

        return $key !== null && $key === self::key($b);
    }

    /**
     * Raw `name=value` pairs, without tracking parameters, sorted. Parsed by hand: `parse_str`
     * would rename `a.b` to `a_b` and fold repeated names.
     */
    private static function canonicalQuery(string $query): string
    {
        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = rawurldecode(str_replace('+', ' ', $name));

            if (self::isTracking($name)) {
                continue;
            }

            $pairs[] = [$name, rawurldecode(str_replace('+', ' ', $value))];
        }

        usort($pairs, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(fn (array $pair): string => rawurlencode($pair[0]).'='.rawurlencode($pair[1]), $pairs));
    }

    private static function isTracking(string $name): bool
    {
        $name = strtolower($name);

        foreach (self::TRACKING_PARAMETERS as $parameter) {
            if (str_ends_with($parameter, '*') ? str_starts_with($name, rtrim($parameter, '*')) : $name === $parameter) {
                return true;
            }
        }

        return false;
    }
}
