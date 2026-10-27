import Foundation

/// The folders under Application Support that hold what devnull sent and what
/// you haven't sent yet: the SQLite database (cached answers, Remotes messages,
/// drafts, the outbox) and Laravel's storage (cached images, files).
private let protectedFolders = ["database", "storage"]

/// Set once every file written before this plugin has its protection raised.
private let upgradedKey = "devnull.dataProtection.upgraded"

/// Called by NativePHP at every launch, before PHP starts (see nativephp.json).
///
/// They are kept out of iCloud and Finder backups: what devnull sent can be
/// fetched again, and a phone restored from a backup signs in again anyway, as
/// its key stays in this phone's Keychain. Unsent drafts don't follow you to
/// another phone. The entitlement makes files written
/// from now on unreadable once the phone locks, unless the app still has them
/// open, which a write in progress needs. Files written before it keep their
/// old protection, so they are raised once here.
func protectAppData() {
    guard let support = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first else {
        return
    }

    let upgrade = !UserDefaults.standard.bool(forKey: upgradedKey)

    for name in protectedFolders {
        var folder = support.appendingPathComponent(name, isDirectory: true)

        do {
            try FileManager.default.createDirectory(at: folder, withIntermediateDirectories: true)

            var values = URLResourceValues()
            values.isExcludedFromBackup = true
            try folder.setResourceValues(values)

            if upgrade {
                raiseProtection(of: folder)
            }
        } catch {
            NSLog("[DataProtection] \(name): \(error.localizedDescription)")
        }
    }

    if upgrade {
        UserDefaults.standard.set(true, forKey: upgradedKey)
    }
}

private func raiseProtection(of folder: URL) {
    let attributes: [FileAttributeKey: Any] = [.protectionKey: FileProtectionType.completeUnlessOpen]

    try? FileManager.default.setAttributes(attributes, ofItemAtPath: folder.path)

    guard let files = FileManager.default.enumerator(at: folder, includingPropertiesForKeys: nil) else {
        return
    }

    for case let file as URL in files {
        try? FileManager.default.setAttributes(attributes, ofItemAtPath: file.path)
    }
}
