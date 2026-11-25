package com.devnull.plugins.photos

import android.content.ContentValues
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.os.Environment
import android.os.Handler
import android.os.Looper
import android.provider.MediaStore
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONObject
import java.io.ByteArrayOutputStream
import java.io.File
import kotlin.concurrent.thread

/**
 * The phone's photo library.
 * Namespace: "Photos.*"
 *
 * Saving returns at once and works off the main thread; what comes of it arrives as an event (Saved or SaveFailed).
 * From Android 10 an app adds its own pictures to the shared collection without asking.
 */
object PhotosFunctions {

    private const val EVENTS = "Devnull\\Photos\\Events\\"

    /** Formats the gallery keeps as they are. Anything else Android can read goes in as a JPEG. */
    private val kept = setOf("image/jpeg", "image/png", "image/gif", "image/webp", "image/heif", "image/heic")

    /**
     * Saves an image file to Pictures/devnull.
     * Parameters:
     *   - path: string - The image file. Its name needn't say what kind of image it is.
     */
    class Save(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val file = (parameters["path"] as? String)?.let(::File)?.takeIf { it.isFile } ?: return mapOf("success" to false)

            thread(name = "devnull-photos-save") { save(activity, file) }

            return mapOf("success" to true)
        }
    }

    private fun save(activity: FragmentActivity, file: File) {
        val (bytes, mime) = photo(file.readBytes()) ?: return dispatch(activity, "SaveFailed", JSONObject().put("reason", "unreadable"))
        val extension = mime.substringAfter('/').replace("jpeg", "jpg")
        val resolver = activity.contentResolver
        val values = ContentValues().apply {
            put(MediaStore.Images.Media.DISPLAY_NAME, "devnull-${System.currentTimeMillis()}.$extension")
            put(MediaStore.Images.Media.MIME_TYPE, mime)
            put(MediaStore.Images.Media.RELATIVE_PATH, Environment.DIRECTORY_PICTURES + "/devnull")
            put(MediaStore.Images.Media.IS_PENDING, 1)
        }

        val uri = try {
            resolver.insert(MediaStore.Images.Media.EXTERNAL_CONTENT_URI, values)
        } catch (e: Exception) {
            null
        } ?: return dispatch(activity, "SaveFailed", JSONObject().put("reason", "error"))

        try {
            resolver.openOutputStream(uri)?.use { it.write(bytes) } ?: throw IllegalStateException("No stream")
            values.clear()
            values.put(MediaStore.Images.Media.IS_PENDING, 0)
            resolver.update(uri, values, null, null)
            dispatch(activity, "Saved", JSONObject())
        } catch (e: Exception) {
            resolver.delete(uri, null, null)
            dispatch(activity, "SaveFailed", JSONObject().put("reason", "error"))
        }
    }

    /** The bytes to store and their type: the file as it is when the gallery keeps that format, a JPEG of it otherwise, or null when it isn't an image. */
    private fun photo(bytes: ByteArray): Pair<ByteArray, String>? {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeByteArray(bytes, 0, bytes.size, bounds)
        val mime = bounds.outMimeType ?: return null

        if (mime in kept) {
            return bytes to mime
        }

        val bitmap = BitmapFactory.decodeByteArray(bytes, 0, bytes.size) ?: return null
        val jpeg = ByteArrayOutputStream().also { bitmap.compress(Bitmap.CompressFormat.JPEG, 95, it) }.toByteArray()

        return jpeg to "image/jpeg"
    }

    private fun dispatch(activity: FragmentActivity, event: String, payload: JSONObject) {
        // Events only reach PHP from the main thread.
        Handler(Looper.getMainLooper()).post { NativeActionCoordinator.dispatchEvent(activity, EVENTS + event, payload.toString()) }
    }
}
