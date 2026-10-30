package com.devnull.plugins.calls

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.media.AudioDeviceInfo
import android.media.AudioManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONObject
import org.webrtc.DataChannel
import org.webrtc.IceCandidate
import org.webrtc.MediaConstraints
import org.webrtc.MediaStream
import org.webrtc.MediaStreamTrack
import org.webrtc.PeerConnection
import org.webrtc.PeerConnectionFactory
import org.webrtc.RtpReceiver
import org.webrtc.RtpTransceiver
import org.webrtc.SdpObserver
import org.webrtc.SessionDescription
import org.webrtc.SoftwareVideoDecoderFactory
import org.webrtc.SoftwareVideoEncoderFactory
import org.webrtc.AudioSource
import org.webrtc.AudioTrack
import org.webrtc.audio.JavaAudioDeviceModule
import java.lang.ref.WeakReference
import java.util.concurrent.CountDownLatch
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit

/**
 * The phone's end of a call, set up exactly as dev-null.sh's browsers set theirs up (resources/js/lib/call.ts there),
 * so either end can be a browser: the microphone, room for video, and a data channel called "call" whose closing
 * means the other end hung up. The offer or answer only goes out once every way to reach the phone is in it, so the
 * server passes on just two messages.
 */
object CallManager {
    data class IceServer(val urls: List<String>, val username: String, val credential: String)

    private const val TAG = "DevnullCalls"
    private const val EVENTS = "Devnull\\Calls\\Events\\"

    /** As long as the browser waits for its candidates before sending what it has. */
    private const val GATHER_SECONDS = 3L
    private const val PERMISSION_REQUEST = 7341

    private val thread = Executors.newSingleThreadExecutor()
    private val main = Handler(Looper.getMainLooper())

    private var activityRef: WeakReference<FragmentActivity>? = null
    private var context: Context? = null
    private var factory: PeerConnectionFactory? = null
    private var peer: PeerConnection? = null
    private var channel: DataChannel? = null
    private var source: AudioSource? = null
    private var microphone: AudioTrack? = null
    private var previousAudioMode: Int? = null

    /**
     * Which call is current. Starting or ending one moves it on, so what a finished call's connection still reports
     * is ignored.
     */
    @Volatile
    private var call = 0

    fun start(activity: FragmentActivity, iceServers: List<IceServer>, isRelayOnly: Boolean, offer: String?) {
        activityRef = WeakReference(activity)
        context = activity.applicationContext

        thread.execute {
            val current = ++call
            close()

            try {
                if (!hasMicrophone(activity)) {
                    ended(current, "microphone")

                    return@execute
                }

                if (current == call) {
                    connect(activity.applicationContext, current, iceServers, isRelayOnly, offer)
                }
            } catch (e: Exception) {
                Log.e(TAG, "Couldn't start the call", e)
                ended(current, "error")
            }
        }
    }

    fun answer(sdp: String) {
        thread.execute {
            val current = call
            val peer = peer ?: return@execute

            try {
                setDescription(peer, SessionDescription(SessionDescription.Type.ANSWER, sdp), isLocal = false)
            } catch (e: Exception) {
                Log.e(TAG, "Couldn't use the answer", e)
                ended(current, "failed")
            }
        }
    }

    fun setMuted(isMuted: Boolean) {
        thread.execute { microphone?.setEnabled(!isMuted) }
    }

    fun setSpeaker(isOn: Boolean) {
        thread.execute { audioManager()?.let { useSpeaker(it, isOn) } }
    }

    fun end() {
        thread.execute {
            call++
            close()
        }
    }

    private fun connect(context: Context, current: Int, iceServers: List<IceServer>, isRelayOnly: Boolean, offer: String?) {
        val factory = factory ?: createFactory(context).also { factory = it }
        useCallAudio()

        val config = PeerConnection.RTCConfiguration(iceServers.map {
            PeerConnection.IceServer.builder(it.urls).setUsername(it.username).setPassword(it.credential).createIceServer()
        }).apply {
            sdpSemantics = PeerConnection.SdpSemantics.UNIFIED_PLAN
            // Only through the relay: a direct route would hand each person the other's IP address.
            iceTransportsType = if (isRelayOnly) PeerConnection.IceTransportsType.RELAY else PeerConnection.IceTransportsType.ALL
            continualGatheringPolicy = PeerConnection.ContinualGatheringPolicy.GATHER_ONCE
        }

        val gathered = CountDownLatch(1)
        val peer = factory.createPeerConnection(config, Observer(current, gathered))
            ?: throw IllegalStateException("No peer connection")
        this.peer = peer

        val source = factory.createAudioSource(MediaConstraints()).also { this.source = it }
        microphone = factory.createAudioTrack("microphone", source).also { peer.addTrack(it, listOf("call")) }

        val description = if (offer == null) {
            // Room for video, as the browser leaves it, so a partner on the web can still turn their camera on.
            peer.addTransceiver(
                MediaStreamTrack.MediaType.MEDIA_TYPE_VIDEO,
                RtpTransceiver.RtpTransceiverInit(RtpTransceiver.RtpTransceiverDirection.SEND_RECV),
            )
            watch(current, peer.createDataChannel("call", DataChannel.Init()))
            createDescription(peer, isOffer = true)
        } else {
            setDescription(peer, SessionDescription(SessionDescription.Type.OFFER, offer), isLocal = false)
            peer.transceivers
                .filter { it.mediaType == MediaStreamTrack.MediaType.MEDIA_TYPE_VIDEO }
                .forEach { it.setDirection(RtpTransceiver.RtpTransceiverDirection.SEND_RECV) }
            createDescription(peer, isOffer = false)
        }

        setDescription(peer, description, isLocal = true)
        gathered.await(GATHER_SECONDS, TimeUnit.SECONDS)

        if (current == call) {
            dispatch("DescriptionReady", JSONObject().put("sdp", peer.localDescription.description))
        }
    }

    private fun createFactory(context: Context): PeerConnectionFactory {
        PeerConnectionFactory.initialize(PeerConnectionFactory.InitializationOptions.builder(context).createInitializationOptions())

        val audioDevice = JavaAudioDeviceModule.builder(context)
            .setUseHardwareAcousticEchoCanceler(true)
            .setUseHardwareNoiseSuppressor(true)
            .createAudioDeviceModule()

        return PeerConnectionFactory.builder()
            .setAudioDeviceModule(audioDevice)
            .setVideoEncoderFactory(SoftwareVideoEncoderFactory())
            .setVideoDecoderFactory(SoftwareVideoDecoderFactory())
            .createPeerConnectionFactory()
    }

    /**
     * Asks for the microphone when it isn't allowed yet. The answer only shows in the permission itself, so it waits
     * while the system's dialog has the screen, and gives up once the dialog goes away or never comes up (when the
     * user said never ask again).
     */
    private fun hasMicrophone(activity: FragmentActivity): Boolean {
        val isGranted = { ContextCompat.checkSelfPermission(activity, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED }

        if (isGranted()) {
            return true
        }

        main.post { ActivityCompat.requestPermissions(activity, arrayOf(Manifest.permission.RECORD_AUDIO), PERMISSION_REQUEST) }

        var sawDialog = false

        repeat(240) { tick ->
            Thread.sleep(250)

            if (isGranted()) {
                return true
            }

            if (!activity.hasWindowFocus()) {
                sawDialog = true
            } else if (sawDialog || tick >= 8) {
                return false
            }
        }

        return false
    }

    private fun watch(current: Int, opened: DataChannel) {
        channel = opened
        opened.registerObserver(object : DataChannel.Observer {
            override fun onBufferedAmountChange(previousAmount: Long) {}

            override fun onStateChange() {
                if (opened.state() == DataChannel.State.CLOSED) {
                    ended(current, "hung_up")
                }
            }

            // The partner's camera going on or off: this end doesn't show video yet.
            override fun onMessage(buffer: DataChannel.Buffer) {}
        })
    }

    private fun ended(current: Int, reason: String) {
        thread.execute {
            if (current != call) {
                return@execute
            }

            call++
            close()
            dispatch("Ended", JSONObject().put("reason", reason))
        }
    }

    private fun close() {
        channel?.let {
            it.unregisterObserver()
            it.close()
            it.dispose()
        }
        channel = null
        peer?.dispose()
        peer = null
        microphone = null
        source?.dispose()
        source = null
        restoreAudio()
    }

    private fun createDescription(peer: PeerConnection, isOffer: Boolean): SessionDescription {
        val latch = CountDownLatch(1)
        var description: SessionDescription? = null
        var error: String? = null
        val observer = object : SdpObserver {
            override fun onCreateSuccess(created: SessionDescription) {
                description = created
                latch.countDown()
            }

            override fun onCreateFailure(message: String?) {
                error = message
                latch.countDown()
            }

            override fun onSetSuccess() {}

            override fun onSetFailure(message: String?) {}
        }

        if (isOffer) {
            peer.createOffer(observer, MediaConstraints())
        } else {
            peer.createAnswer(observer, MediaConstraints())
        }

        latch.await(10, TimeUnit.SECONDS)

        return description ?: throw IllegalStateException(error ?: "No session description")
    }

    private fun setDescription(peer: PeerConnection, description: SessionDescription, isLocal: Boolean) {
        val latch = CountDownLatch(1)
        var error: String? = "Timed out"
        val observer = object : SdpObserver {
            override fun onCreateSuccess(created: SessionDescription) {}

            override fun onCreateFailure(message: String?) {}

            override fun onSetSuccess() {
                error = null
                latch.countDown()
            }

            override fun onSetFailure(message: String?) {
                error = message ?: "Couldn't set the session description"
                latch.countDown()
            }
        }

        if (isLocal) {
            peer.setLocalDescription(observer, description)
        } else {
            peer.setRemoteDescription(observer, description)
        }

        latch.await(10, TimeUnit.SECONDS)
        error?.let { throw IllegalStateException(it) }
    }

    private fun audioManager(): AudioManager? = context?.getSystemService(Context.AUDIO_SERVICE) as? AudioManager

    private fun useCallAudio() {
        val audio = audioManager() ?: return

        if (previousAudioMode == null) {
            previousAudioMode = audio.mode
        }

        audio.mode = AudioManager.MODE_IN_COMMUNICATION
        useSpeaker(audio, false)
    }

    private fun restoreAudio() {
        val audio = audioManager() ?: return
        val mode = previousAudioMode ?: return

        useSpeaker(audio, false)
        audio.mode = mode
        previousAudioMode = null
    }

    private fun useSpeaker(audio: AudioManager, isOn: Boolean) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            val speaker = audio.availableCommunicationDevices.firstOrNull { it.type == AudioDeviceInfo.TYPE_BUILTIN_SPEAKER }

            if (isOn && speaker != null) {
                audio.setCommunicationDevice(speaker)
            } else {
                audio.clearCommunicationDevice()
            }
        } else {
            @Suppress("DEPRECATION")
            audio.isSpeakerphoneOn = isOn
        }
    }

    private fun dispatch(event: String, payload: JSONObject) {
        val activity = activityRef?.get() ?: return

        // Events only reach PHP from the main thread.
        main.post { NativeActionCoordinator.dispatchEvent(activity, EVENTS + event, payload.toString()) }
    }

    private class Observer(private val current: Int, private val gathered: CountDownLatch) : PeerConnection.Observer {
        override fun onIceGatheringChange(state: PeerConnection.IceGatheringState) {
            if (state == PeerConnection.IceGatheringState.COMPLETE) {
                gathered.countDown()
            }
        }

        override fun onConnectionChange(state: PeerConnection.PeerConnectionState) {
            when (state) {
                PeerConnection.PeerConnectionState.CONNECTED -> if (current == call) dispatch("Connected", JSONObject())
                PeerConnection.PeerConnectionState.FAILED -> ended(current, "failed")
                else -> {}
            }
        }

        override fun onDataChannel(channel: DataChannel) {
            thread.execute {
                if (current == call) {
                    watch(current, channel)
                }
            }
        }

        override fun onSignalingChange(state: PeerConnection.SignalingState) {}

        override fun onIceConnectionChange(state: PeerConnection.IceConnectionState) {}

        override fun onIceConnectionReceivingChange(receiving: Boolean) {}

        override fun onIceCandidate(candidate: IceCandidate) {}

        override fun onIceCandidatesRemoved(candidates: Array<out IceCandidate>) {}

        override fun onAddStream(stream: MediaStream) {}

        override fun onRemoveStream(stream: MediaStream) {}

        override fun onRenegotiationNeeded() {}

        override fun onAddTrack(receiver: RtpReceiver, streams: Array<out MediaStream>) {}
    }
}
