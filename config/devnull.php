<?php

use Composer\InstalledVersions;

return [

    /*
    |--------------------------------------------------------------------------
    | devnull URL
    |--------------------------------------------------------------------------
    |
    | The base URL of the devnull web app this client talks to. Point it at a
    | local copy while developing and leave it unset to use production.
    |
    */

    'url' => env('DEVNULL_URL', 'https://dev-null.sh'),

    /*
    |--------------------------------------------------------------------------
    | GitHub OAuth app
    |--------------------------------------------------------------------------
    |
    | The client ID of the OAuth app that adds this phone's signing key to
    | GitHub through the device flow. The device flow needs no secret. Leave
    | it unset and onboarding only offers adding the key by hand.
    |
    */

    'github_client_id' => env('DEVNULL_GITHUB_CLIENT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Device key store
    |--------------------------------------------------------------------------
    |
    | Where this phone's key is kept: "keychain" needs the paid Secure Storage
    | plugin, "file" is an encrypted file for building without it. Leave it
    | unset to use the Keychain whenever the plugin is installed.
    |
    */

    'key_store' => env('DEVNULL_KEY_STORE', InstalledVersions::isInstalled('nativephp/mobile-secure-storage') ? 'keychain' : 'file'),

];
