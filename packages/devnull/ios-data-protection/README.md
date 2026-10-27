# devnull/ios-data-protection

A NativePHP Mobile plugin, iOS only, that protects what the app keeps on the phone:

- **Out of backups.** At every launch, `protectAppData()` marks `database/` and `storage/` under Application Support with
  `isExcludedFromBackup`, so the SQLite database and Laravel's storage never reach iCloud or Finder backups.
- **Locked away while the phone is locked.** The `com.apple.developer.default-data-protection` entitlement is set to
  `NSFileProtectionCompleteUnlessOpen`: files are unreadable once the phone locks, except those the app still has open,
  so a write in progress finishes. Files written before the entitlement keep their old protection, so the first launch
  with the plugin raises them once.

It has no PHP API. Register `DataProtectionServiceProvider` in the app's `NativeServiceProvider::plugins()` and rebuild.

Nothing here is specific to devnull: any NativePHP app that keeps private data in its database could use it, and it
could move into NativePHP itself.
