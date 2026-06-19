<?php

// Regression coverage for the ACME cert-resolver spam: Let's Encrypt rejects orders for bare
// IP addresses, so Traefik must not be told to resolve a certificate for an IP-literal host.

it('does not request an ACME cert resolver for a bare IPv4 domain', function () {
    $labels = fqdnLabelsForTraefik('ipuuid', collect(['https://203.0.113.10']))->implode("\n");

    expect($labels)
        ->toContain('traefik.http.routers.https-0-ipuuid.tls=true')
        ->and($labels)->not->toContain('traefik.http.routers.https-0-ipuuid.tls.certresolver=');
});

it('still requests an ACME cert resolver for a normal hostname', function () {
    $labels = fqdnLabelsForTraefik('hostuuid', collect(['https://app.example.com']))->implode("\n");

    expect($labels)
        ->toContain('traefik.http.routers.https-0-hostuuid.tls=true')
        ->and($labels)->toContain('traefik.http.routers.https-0-hostuuid.tls.certresolver='.traefikCertResolverName());
});

it('does not add a cert resolver for an IP domain even when public cert resolver is requested', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'ipuuid2',
        domains: collect(['https://198.51.100.5']),
        use_public_cert_resolver: true,
    )->implode("\n");

    expect($labels)->not->toContain('.tls.certresolver=');
});
