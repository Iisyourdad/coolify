<?php

it('opens the service logs slide-over when confirming a deploy', function () {
    $serviceHeadingView = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));

    $startEventListener = str($serviceHeadingView)
        ->between('$wire.$on(\'startEvent\', async () => {', '$wire.$on(\'forceDeployEvent\', () => {')
        ->value();

    expect($startEventListener)
        ->toContain("window.dispatchEvent(new CustomEvent('startservice'));")
        ->toContain('$wire.$call(\'start\');');
});
