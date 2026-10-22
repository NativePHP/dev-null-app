<?php

namespace Devnull\CommitRow\Components;

use Native\Mobile\Edge\Components\Native\NativeBladeComponent;

class CommitRow extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'commit_row';
    }
}
