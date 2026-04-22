<?php

it('detects wildcard hostnames', function () {
    expect(containsWildcardHostname('https://*.example.com'))->toBeTrue()
        ->and(containsWildcardHostname('https://example.com'))->toBeFalse()
        ->and(containsWildcardHostname('*.example.com'))->toBeTrue()
        ->and(containsWildcardHostname('app.example.com'))->toBeFalse();
});
