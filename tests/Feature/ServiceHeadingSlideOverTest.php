<?php

use Illuminate\Support\Facades\Blade;

it('opens the service logs slide-over when confirming a deploy', function () {
    $serviceHeadingView = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));

    $startEventListener = str($serviceHeadingView)
        ->between('$wire.$on(\'startEvent\', async () => {', '$wire.$on(\'forceDeployEvent\', () => {')
        ->value();

    expect($startEventListener)
        ->toContain("window.dispatchEvent(new CustomEvent('startservice'));")
        ->toContain('$wire.$call(\'start\');');
});

it('compiles the service heading and resolves its Blade components', function () {
    $source = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));

    expect(Blade::compileString($source))->toContain('components.split-action');
});

it('renders split actions with the main button attributes and menu', function () {
    $html = Blade::render(<<<'BLADE'
        <x-split-action id="service-actions" class="w-full">
            <x-slot:main wire:click="start" x-bind:disabled="deploying">Deploy</x-slot:main>
            <button type="button" role="menuitem">Force Deploy</button>
        </x-split-action>
    BLADE);

    expect($html)
        ->toContain('id="service-actions"')
        ->toContain('wire:click="start"')
        ->toContain('x-bind:disabled="deploying"')
        ->toContain('Deploy')
        ->toContain('Force Deploy')
        ->toContain('aria-label="More actions"')
        ->toContain('role="menu"');
});

it('renders split actions without an empty dropdown', function () {
    $html = Blade::render(<<<'BLADE'
        <x-split-action>
            <x-slot:main disabled>Deploy</x-slot:main>
        </x-split-action>
    BLADE);

    expect($html)->toContain('disabled')->toContain('Deploy')
        ->not->toContain('aria-label="More actions"')
        ->not->toContain('role="menu"');
});
