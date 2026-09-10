<?php

use App\Services\PublishedPortMappingParser;

it('parses published TCP and UDP mappings but excludes loopback and expose-only values', function () {
    $mappings = app(PublishedPortMappingParser::class)->parse(['25565:25565/tcp', '19132:19132/udp', '127.0.0.1:3000:3000', '8080']);
    expect($mappings->all())->toBe([
        ['published' => 25565, 'protocol' => 'tcp'],
        ['published' => 19132, 'protocol' => 'udp'],
    ]);
});

it('resolves Compose long syntax environment values after validation', function () {
    $mappings = app(PublishedPortMappingParser::class)->parse([
        ['target' => 19132, 'published' => '${BEDROCK_PORT}', 'protocol' => 'udp'],
    ], ['BEDROCK_PORT' => '19132']);
    expect($mappings->all())->toBe([['published' => 19132, 'protocol' => 'udp']]);
});
