<?php

it('generates database labels without relying on an application variable', function () {
    $database = Mockery::mock();
    $database->id = 42;
    $database->name = 'Primary Database';
    $database->environment = (object) ['name' => 'Production'];

    $database->shouldReceive('project')->once()->andReturn((object) ['name' => 'Example Project']);
    $database->shouldReceive('type')->once()->andReturn('standalone-postgresql');

    $labels = defaultDatabaseLabels($database)->values()->all();

    expect($labels)->toContain('coolify.managed=true')
        ->and($labels)->toContain('coolify.type=database')
        ->and($labels)->toContain('coolify.databaseId=42')
        ->and($labels)->toContain('coolify.resourceName=primary-database')
        ->and($labels)->toContain('coolify.projectName=example-project')
        ->and($labels)->toContain('coolify.environmentName=production')
        ->and($labels)->toContain('coolify.database.subType=standalone-postgresql');
});
