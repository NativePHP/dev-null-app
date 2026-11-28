<?php

/*
|--------------------------------------------------------------------------
| Over-the-air updates
|--------------------------------------------------------------------------
|
| Only the mode lives here. The plugin's own config fills in the rest from
| what Bifrost writes into the store build.
|
| "silent" checks once per launch in the background, downloads any update
| and applies it the next time the app starts. Nothing is shown on screen.
| The mode is compiled into each store build, so changing it takes a new
| one. A build that Bifrost didn't make skips the check.
|
*/

return [

    'mode' => env('NATIVEPHP_OTA_MODE', 'silent'),

];
