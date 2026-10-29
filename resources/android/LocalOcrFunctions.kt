package dev.firstlightui.plugins.local_ocr

import android.app.Activity
import android.app.ActivityManager
import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.graphics.Rect
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import android.util.Log
import androidx.activity.result.IntentSenderRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.exifinterface.media.ExifInterface
import androidx.fragment.app.FragmentActivity
import com.google.android.gms.common.ConnectionResult
import com.google.android.gms.common.GoogleApiAvailability
import com.google.mlkit.vision.common.InputImage
import com.google.mlkit.vision.documentscanner.GmsDocumentScannerOptions
import com.google.mlkit.vision.documentscanner.GmsDocumentScanning
import com.google.mlkit.vision.documentscanner.GmsDocumentScanningResult
import com.google.mlkit.vision.text.Text
import com.google.mlkit.vision.text.TextRecognition
import com.google.mlkit.vision.text.latin.TextRecognizerOptions
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.util.concurrent.Executors

/**
 * Multi-page document capture (ML Kit Document Scanner) and on-device OCR
 * (ML Kit Text Recognition, Latin script).
 * Namespace: "LocalOcr.*"
 */
object LocalOcrFunctions {

    private const val TAG = "LocalOcr"
    private const val CONTRACT_VERSION = 1
    private const val ENGINE = "mlkit-text-recognition"
    private const val CAPTURE_ENGINE = "mlkit-document-scanner"
    private const val RECOGNIZED_EVENT = "FirstlightUI\\LocalOcr\\Events\\TextRecognized"
    private const val FAILED_EVENT = "FirstlightUI\\LocalOcr\\Events\\TextRecognitionFailed"
    private const val CAPTURED_EVENT = "FirstlightUI\\LocalOcr\\Events\\PagesCaptured"
    private const val CAPTURE_CANCELLED_EVENT = "FirstlightUI\\LocalOcr\\Events\\CaptureCancelled"
    private const val CAPTURE_FAILED_EVENT = "FirstlightUI\\LocalOcr\\Events\\CaptureFailed"

    // Larger images are downsampled before recognition. Boxes are normalized,
    // so the result does not depend on the decode size.
    private const val MAX_DIMENSION = 4096

    // Captures left behind by the app are deleted on the next capture.
    private const val STALE_CAPTURE_MS = 24 * 60 * 60 * 1000L

    private val worker = Executors.newSingleThreadExecutor()
    private val mainHandler = Handler(Looper.getMainLooper())

    /** Returns the capability contract. */
    class GetCapabilities(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val memory = ActivityManager.MemoryInfo()
            (activity.getSystemService(Context.ACTIVITY_SERVICE) as ActivityManager).getMemoryInfo(memory)
            val playServices = GoogleApiAvailability.getInstance().isGooglePlayServicesAvailable(activity) ==
                ConnectionResult.SUCCESS

            return BridgeResponse.success(mapOf(
                "contractVersion" to CONTRACT_VERSION,
                "platform" to "android",
                "osVersion" to Build.VERSION.RELEASE,
                "deviceModel" to "${Build.MANUFACTURER} ${Build.MODEL}",
                "totalMemoryBytes" to memory.totalMem,
                "capture" to mapOf(
                    "available" to playServices,
                    "engine" to CAPTURE_ENGINE,
                    "reason" to (if (playServices) JSONObject.NULL else "googlePlayServicesUnavailable")
                ),
                "ocr" to mapOf(
                    "available" to true,
                    "engine" to ENGINE,
                    "onDevice" to true,
                    "languages" to JSONObject.NULL,
                    "recognitionLevels" to listOf("accurate")
                )
            ))
        }
    }

    /**
     * Opens the system document scanner. Parameters:
     *   - id: string (required) - correlates the result event
     * Returns { status: "pending", id } and later dispatches PagesCaptured,
     * CaptureCancelled or CaptureFailed. Pages are JPEGs in the app's private
     * cache directory.
     */
    class Capture(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters["id"] as? String ?: ""
            if (id.isEmpty()) {
                return captureFail(activity, id, "INVALID_PARAMETERS", "id is required")
            }

            worker.execute { purgeStaleCaptures(activity) }
            mainHandler.post { startScanner(activity, id) }

            return BridgeResponse.success(mapOf("status" to "pending", "id" to id))
        }
    }

    /**
     * Deletes captured pages. Parameters:
     *   - paths: [string] - page paths from PagesCaptured
     * Only files inside this plugin's capture directory are deleted.
     */
    class Discard(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val root = captureRoot(activity).canonicalFile
            var deleted = 0

            for (path in stringList(parameters["paths"])) {
                val file = File(path.removePrefix("file://")).canonicalFile
                if (!file.path.startsWith(root.path + File.separator)) continue

                if (file.isFile && file.delete()) deleted++
                file.parentFile?.takeIf { it != root && it.list()?.isEmpty() == true }?.delete()
            }

            return BridgeResponse.success(mapOf("deleted" to deleted))
        }
    }

    /**
     * Parameters:
     *   - id: string (required) - correlates the result event
     *   - paths: [string] (required) - absolute paths or file:// URLs, in page order
     * Returns { status: "pending", id } and later dispatches TextRecognized
     * or TextRecognitionFailed.
     */
    class Recognize(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters["id"] as? String ?: ""
            val paths = stringList(parameters["paths"]).map { it.removePrefix("file://") }

            if (id.isEmpty() || paths.isEmpty()) {
                return fail(activity, id, "INVALID_PARAMETERS", "id and at least one path are required")
            }

            paths.firstOrNull { !File(it).isFile }?.let {
                return fail(activity, id, "FILE_NOT_FOUND", "No image file at $it")
            }

            worker.execute { recognizePages(activity, id, paths.map(::File)) }

            return BridgeResponse.success(mapOf("status" to "pending", "id" to id))
        }
    }

    // MARK: Capture

    private fun startScanner(activity: FragmentActivity, id: String) {
        val options = GmsDocumentScannerOptions.Builder()
            .setGalleryImportAllowed(false)
            .setResultFormats(GmsDocumentScannerOptions.RESULT_FORMAT_JPEG)
            .setScannerMode(GmsDocumentScannerOptions.SCANNER_MODE_FULL)
            .build()

        GmsDocumentScanning.getClient(options).getStartScanIntent(activity)
            .addOnSuccessListener(activity) { intentSender ->
                // A keyed registration can be made after onStart, unlike
                // registerForActivityResult, so it works from a bridge call.
                var launcher: androidx.activity.result.ActivityResultLauncher<IntentSenderRequest>? = null
                launcher = activity.activityResultRegistry.register(
                    "local-ocr-capture-$id",
                    ActivityResultContracts.StartIntentSenderForResult()
                ) { result ->
                    launcher?.unregister()
                    val scan = GmsDocumentScanningResult.fromActivityResultIntent(result.data)

                    when {
                        result.resultCode == Activity.RESULT_CANCELED ->
                            dispatch(activity, CAPTURE_CANCELLED_EVENT, JSONObject().put("id", id))
                        result.resultCode != Activity.RESULT_OK || scan?.pages.isNullOrEmpty() ->
                            captureFail(activity, id, "CAPTURE_FAILED", "The scanner returned no pages")
                        else -> worker.execute { savePages(activity, id, scan!!.pages!!.map { it.imageUri }) }
                    }
                }
                launcher.launch(IntentSenderRequest.Builder(intentSender).build())
            }
            .addOnFailureListener(activity) { e ->
                Log.e(TAG, "Document scanner unavailable", e)
                captureFail(activity, id, "CAPTURE_UNAVAILABLE", e.message ?: "The document scanner is unavailable")
            }
    }

    private fun savePages(activity: FragmentActivity, id: String, uris: List<android.net.Uri>) {
        val directory = File(captureRoot(activity), id).apply { mkdirs() }
        val pages = JSONArray()

        try {
            uris.forEachIndexed { index, uri ->
                val file = File(directory, "page-${index + 1}.jpg")
                activity.contentResolver.openInputStream(uri).use { input ->
                    requireNotNull(input) { "Cannot read scanned page ${index + 1}" }
                    file.outputStream().use { input.copyTo(it) }
                }
                val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
                BitmapFactory.decodeFile(file.path, bounds)
                pages.put(JSONObject()
                    .put("path", file.path)
                    .put("width", bounds.outWidth)
                    .put("height", bounds.outHeight))
            }
        } catch (e: Exception) {
            Log.e(TAG, "Saving scanned pages failed", e)
            directory.deleteRecursively()
            captureFail(activity, id, "SAVE_FAILED", e.message ?: "Could not save the scanned pages")
            return
        }

        dispatch(activity, CAPTURED_EVENT, JSONObject().put("id", id).put("pages", pages))
    }

    private fun captureRoot(context: Context) = File(context.cacheDir, "local-ocr")

    private fun purgeStaleCaptures(context: Context) {
        val cutoff = System.currentTimeMillis() - STALE_CAPTURE_MS
        captureRoot(context).listFiles()?.filter { it.lastModified() < cutoff }?.forEach { it.deleteRecursively() }
    }

    // MARK: Recognition

    private fun recognizePages(activity: FragmentActivity, id: String, files: List<File>) {
        val startedAt = SystemClock.elapsedRealtime()
        val recognizer = TextRecognition.getClient(TextRecognizerOptions.DEFAULT_OPTIONS)
        val pages = JSONArray()

        try {
            files.forEachIndexed { index, file ->
                val pageStartedAt = SystemClock.elapsedRealtime()
                val bitmap = try {
                    decodeUpright(file)
                } catch (e: Exception) {
                    Log.e(TAG, "Decoding ${file.path} failed", e)
                    null
                } ?: run {
                    fail(activity, id, "DECODE_FAILED", "Could not decode page ${index + 1} at ${file.path}")
                    return
                }

                val width = bitmap.width
                val height = bitmap.height
                val text = try {
                    // Blocking is fine here: this runs on the plugin's worker thread.
                    com.google.android.gms.tasks.Tasks.await(recognizer.process(InputImage.fromBitmap(bitmap, 0)))
                } catch (e: Exception) {
                    Log.e(TAG, "Recognition failed", e)
                    fail(activity, id, "RECOGNITION_FAILED", "Page ${index + 1}: ${e.message ?: "text recognition failed"}")
                    return
                } finally {
                    bitmap.recycle()
                }

                pages.put(toPage(text, index + 1, file.path, width, height, SystemClock.elapsedRealtime() - pageStartedAt))
            }
        } finally {
            recognizer.close()
        }

        val result = JSONObject()
            .put("contractVersion", CONTRACT_VERSION)
            .put("engine", ENGINE)
            .put("text", (0 until pages.length()).joinToString("\n\n") { pages.getJSONObject(it).getString("text") })
            .put("pages", pages)
            .put("durationMs", SystemClock.elapsedRealtime() - startedAt)

        dispatch(activity, RECOGNIZED_EVENT, JSONObject().put("id", id).put("result", result))
    }

    /** Decode with EXIF orientation applied, so boxes match the upright image. */
    private fun decodeUpright(file: File): Bitmap? {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeFile(file.path, bounds)
        if (bounds.outWidth <= 0 || bounds.outHeight <= 0) return null

        var sampleSize = 1
        while (maxOf(bounds.outWidth, bounds.outHeight) / sampleSize > MAX_DIMENSION) sampleSize *= 2

        val bitmap = BitmapFactory.decodeFile(file.path, BitmapFactory.Options().apply { inSampleSize = sampleSize })
            ?: return null

        val exif = ExifInterface(file.path)
        val matrix = Matrix().apply {
            setRotate(exif.rotationDegrees.toFloat())
            if (exif.isFlipped) postScale(-1f, 1f)
        }
        if (matrix.isIdentity) return bitmap

        val upright = Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, matrix, true)
        if (upright !== bitmap) bitmap.recycle()
        return upright
    }

    private fun toPage(text: Text, page: Int, path: String, width: Int, height: Int, durationMs: Long): JSONObject {
        val blocks = JSONArray()

        for (block in text.textBlocks) {
            val lines = JSONArray()
            for (line in block.lines) {
                val words = JSONArray()
                for (element in line.elements) {
                    words.put(JSONObject()
                        .put("text", element.text)
                        .put("confidence", round(element.confidence.toDouble()))
                        .put("boundingBox", box(element.boundingBox, width, height)))
                }
                lines.put(JSONObject()
                    .put("text", line.text)
                    .put("confidence", round(line.confidence.toDouble()))
                    .put("boundingBox", box(line.boundingBox, width, height))
                    .put("words", words))
            }
            blocks.put(JSONObject()
                .put("text", block.text)
                .put("boundingBox", box(block.boundingBox, width, height))
                .put("lines", lines))
        }

        return JSONObject()
            .put("page", page)
            .put("path", path)
            .put("image", JSONObject().put("width", width).put("height", height))
            .put("text", text.textBlocks.joinToString("\n") { it.text })
            .put("blocks", blocks)
            .put("durationMs", durationMs)
    }

    /** Normalized to 0..1 with a top-left origin. */
    private fun box(rect: Rect?, width: Int, height: Int): Any {
        if (rect == null) return JSONObject.NULL

        val left = (rect.left.toDouble() / width).coerceIn(0.0, 1.0)
        val top = (rect.top.toDouble() / height).coerceIn(0.0, 1.0)
        val right = (rect.right.toDouble() / width).coerceIn(0.0, 1.0)
        val bottom = (rect.bottom.toDouble() / height).coerceIn(0.0, 1.0)

        return JSONObject()
            .put("x", round(left))
            .put("y", round(top))
            .put("width", round(right - left))
            .put("height", round(bottom - top))
    }

    private fun round(value: Double): Double = Math.round(value * 100_000) / 100_000.0

    // MARK: Helpers

    private fun stringList(value: Any?): List<String> = when (value) {
        is List<*> -> value.filterIsInstance<String>().filter { it.isNotEmpty() }
        is org.json.JSONArray -> (0 until value.length()).map { value.optString(it) }.filter { it.isNotEmpty() }
        else -> emptyList()
    }

    private fun fail(activity: FragmentActivity, id: String, code: String, message: String): Map<String, Any> {
        dispatch(activity, FAILED_EVENT, JSONObject().put("id", id).put("code", code).put("message", message))

        return BridgeResponse.error(code, message)
    }

    private fun captureFail(activity: FragmentActivity, id: String, code: String, message: String): Map<String, Any> {
        dispatch(activity, CAPTURE_FAILED_EVENT, JSONObject().put("id", id).put("code", code).put("message", message))

        return BridgeResponse.error(code, message)
    }

    /** Events reach the WebView and PHP only when dispatched on the main thread. */
    private fun dispatch(activity: FragmentActivity, event: String, payload: JSONObject) {
        mainHandler.post { NativeActionCoordinator.dispatchEvent(activity, event, payload.toString()) }
    }
}
