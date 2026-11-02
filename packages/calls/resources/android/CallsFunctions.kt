package com.devnull.plugins.calls

import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import org.json.JSONArray

/**
 * One-to-one WebRTC calls.
 * Namespace: "Calls.*"
 *
 * Every function returns at once: the work happens on the call's own thread, and what comes of it arrives as events
 * (DescriptionReady, Connected, Ended).
 */
object CallsFunctions {

    /**
     * Opens the microphone and starts a connection.
     * Parameters:
     *   - ice_servers: array of {urls, username, credential}
     *   - is_relay_only: boolean - Only connect through the relay, so neither end sees the other's IP address
     *   - offer: (optional) string - The partner's offer to answer; without it, this phone makes the offer
     */
    class Start(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val servers = (parameters["ice_servers"] as? JSONArray) ?: JSONArray()
            val iceServers = (0 until servers.length()).mapNotNull { servers.optJSONObject(it) }.map { server ->
                val urls = server.opt("urls")
                CallManager.IceServer(
                    urls = if (urls is JSONArray) (0 until urls.length()).map { urls.getString(it) } else listOfNotNull(urls?.toString()),
                    username = server.optString("username"),
                    credential = server.optString("credential"),
                )
            }
            val isRelayOnly = parameters["is_relay_only"] as? Boolean ?: true
            // A null offer arrives as JSONObject.NULL, which isn't a String.
            val offer = (parameters["offer"] as? String)?.takeIf { it.isNotEmpty() }

            CallManager.start(activity, iceServers, isRelayOnly, offer)

            return mapOf("success" to true)
        }
    }

    /**
     * Applies the partner's answer to this phone's offer.
     * Parameters:
     *   - sdp: string
     */
    class Answer(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val sdp = parameters["sdp"] as? String ?: return mapOf("success" to false)
            CallManager.answer(sdp)

            return mapOf("success" to true)
        }
    }

    /**
     * Parameters:
     *   - muted: boolean
     */
    class SetMuted(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            CallManager.setMuted(parameters["muted"] as? Boolean ?: false)

            return mapOf("success" to true)
        }
    }

    /**
     * Parameters:
     *   - on: boolean - Loudspeaker when true, earpiece when false
     */
    class SetSpeaker(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            CallManager.setSpeaker(parameters["on"] as? Boolean ?: false)

            return mapOf("success" to true)
        }
    }

    /**
     * Hangs up, which closes the call on the partner's side too.
     */
    class End(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            CallManager.end()

            return mapOf("success" to true)
        }
    }
}
