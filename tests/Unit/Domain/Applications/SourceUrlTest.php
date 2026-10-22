<?php

use App\Domain\Applications\SourceUrl;

test('the same listing reached in different ways has one key', function (string $variant) {
    expect(SourceUrl::key($variant))->toBe('jobs.example.com/listing/123?id=9');
})->with([
    'plain' => 'https://jobs.example.com/listing/123?id=9',
    'www and uppercase host' => 'https://WWW.Jobs.Example.com/listing/123?id=9',
    'trailing slash and fragment' => 'https://jobs.example.com/listing/123/?id=9#apply',
    'default port' => 'https://jobs.example.com:443/listing/123?id=9',
    'http' => 'http://jobs.example.com/listing/123?id=9',
    'tracking parameters' => 'https://jobs.example.com/listing/123?utm_source=x&id=9&gclid=abc&trackingId=q&refId=r',
    'surrounding whitespace' => "  https://jobs.example.com/listing/123?id=9\n",
]);

test('meaningful query parameters are kept and order does not matter', function () {
    expect(SourceUrl::same('https://x.test/view?jk=1&from=a', 'https://x.test/view?from=a&jk=1'))->toBeTrue()
        ->and(SourceUrl::same('https://x.test/view?jk=1', 'https://x.test/view?jk=2'))->toBeFalse()
        ->and(SourceUrl::key('https://x.test/view?a.b=1'))->toBe('x.test/view?a.b=1');
});

test('path case and non-default ports are significant', function () {
    expect(SourceUrl::same('https://x.test/Job/A', 'https://x.test/job/a'))->toBeFalse()
        ->and(SourceUrl::key('https://x.test:8443/job'))->toBe('x.test:8443/job');
});

test('malformed or non-web URLs have no key', function (string $url) {
    expect(SourceUrl::key($url))->toBeNull()
        ->and(SourceUrl::same($url, $url))->toBeFalse();
})->with(['', 'not a url', '/relative/path', 'javascript:alert(1)', 'mailto:jobs@example.com', 'ftp://x.test/a', 'http://']);
