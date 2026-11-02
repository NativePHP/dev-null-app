<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Layouts\Builders\Tab;

it('registers a tab reselect callback on the bottom nav item', function () {
    $tab = Tab::link('Log', '/log')->onReselect('backToTopAndFetchNewest');

    $props = $tab->toElement()->getResolvedProps(new CallbackRegistry);

    expect($props['on_reselect'] ?? null)->toBeInt()->toBeGreaterThan(0);
});
