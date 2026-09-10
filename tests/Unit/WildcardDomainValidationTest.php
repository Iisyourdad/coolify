<?php

it('detects wildcard hostnames', function () {
    expect(containsWildcardHostname('https://*.example.com'))->toBeTrue()
        ->and(containsWildcardHostname('https://example.com'))->toBeFalse()
        ->and(containsWildcardHostname('*.example.com'))->toBeTrue()
        ->and(containsWildcardHostname('app.example.com'))->toBeFalse()
        ->and(containsWildcardHostname('https://app.example.com/path/*'))->toBeFalse()
        ->and(containsWildcardHostname('https://app.example.com/?search=*'))->toBeFalse();
});
