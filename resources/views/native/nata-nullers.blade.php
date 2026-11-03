{{--
    Nata Nu//ers has the whole screen, with no bar: the canvas draws the game and everything on it, the score, Quit,
    the controls and the results when you're knocked over (see Scenery and Layout). It keeps itself clear of the status
    bar and the home indicator. A phone squarer than the scene gets black bars at the sides rather than losing anything.
--}}
<native:column class="w-full h-full bg-black">
    <native:skia-canvas
        :scene="$scene"
        canvas-id="{{ $canvasId }}"
        fit="contain"
        :animated="$playing"
        interactive
        class="w-full h-full"
        a11y-label="{{ \App\Devnull\NataNullers\Game::SpokenName }}. Catch the falling pastéis and jump whatever comes along."
    />
</native:column>
