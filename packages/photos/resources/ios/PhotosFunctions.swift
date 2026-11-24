import Foundation
import ImageIO
import Photos
import UIKit
import UniformTypeIdentifiers

// MARK: - Photos Function Namespace

/// The phone's photo library.
/// Namespace: "Photos.*"
///
/// Saving returns at once: the phone may ask the person first, and what comes of it arrives as an event (Saved or
/// SaveFailed).
enum PhotosFunctions {

    private static let events = "Devnull\\Photos\\Events\\"

    /// Formats the photo library keeps as they are. Anything else the phone can read goes in as a JPEG.
    private static let kept: Set<String> = [
        UTType.jpeg.identifier,
        UTType.png.identifier,
        UTType.heic.identifier,
        UTType.gif.identifier,
    ]

    // MARK: - Photos.Save

    /// Saves an image file to the photo library, asking to add to it first if the person hasn't been asked.
    /// Parameters:
    ///   - path: string - The image file. Its name needn't say what kind of image it is.
    class Save: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let path = parameters["path"] as? String,
                  let data = FileManager.default.contents(atPath: path) else {
                return ["success": false]
            }

            DispatchQueue.global(qos: .userInitiated).async {
                PhotosFunctions.save(data)
            }

            return ["success": true]
        }
    }

    private static func save(_ data: Data) {
        guard let (image, type) = photo(from: data) else {
            dispatch("SaveFailed", ["reason": "unreadable"])
            return
        }

        PHPhotoLibrary.requestAuthorization(for: .addOnly) { status in
            guard status == .authorized || status == .limited else {
                dispatch("SaveFailed", ["reason": "denied"])
                return
            }

            PHPhotoLibrary.shared().performChanges({
                let options = PHAssetResourceCreationOptions()
                options.uniformTypeIdentifier = type
                PHAssetCreationRequest.forAsset().addResource(with: .photo, data: image, options: options)
            }) { saved, _ in
                dispatch(saved ? "Saved" : "SaveFailed", saved ? [:] : ["reason": "error"])
            }
        }
    }

    /// The bytes to give the library and their type: the file as it is when the library keeps that format, a JPEG of
    /// it otherwise (WebP, say), or nil when it isn't an image.
    private static func photo(from data: Data) -> (Data, String)? {
        guard let source = CGImageSourceCreateWithData(data as CFData, nil),
              let type = CGImageSourceGetType(source) as String? else {
            return nil
        }

        if kept.contains(type) {
            return (data, type)
        }

        guard let jpeg = UIImage(data: data)?.jpegData(compressionQuality: 0.95) else {
            return nil
        }

        return (jpeg, UTType.jpeg.identifier)
    }

    private static func dispatch(_ event: String, _ payload: [String: Any]) {
        // Events only reach PHP from the main thread.
        DispatchQueue.main.async {
            LaravelBridge.shared.send?(events + event, payload)
        }
    }
}
