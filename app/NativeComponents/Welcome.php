<?php

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * The web's welcome page: what devnull is, as a short git log, and one way in.
 *
 * Nothing touches the Keychain here. The key is made once someone chooses to sign in.
 */
class Welcome extends NativeComponent
{
    public function signInWithGitHub(): void
    {
        $this->navigate('/onboarding', ['github' => true]);
    }

    public function render(): View
    {
        return view('native.welcome');
    }
}
