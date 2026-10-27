<?php

namespace Devnull\IosDataProtection;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the plugin with NativePHP. Its work is all native: the
 * entitlement in nativephp.json and protectAppData() at launch.
 */
class DataProtectionServiceProvider extends ServiceProvider {}
