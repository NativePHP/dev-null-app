{{--
    One of the web's filter chips, as the native chip draws them: on in the theme's primary, off in its outline,
    selected state and all, which a screen reader announces as selected or not — the web's aria-pressed. Its press
    goes to the screen, which owns the selection and hands the chip its state back.
--}}
@php($on = in_array($category, $enabled, true))
<native:chip ref="filter-{{ $category }}" label="{{ $categories[$category][0] }}" :selected="$on" @press="toggle('{{ $category }}')" a11y-label="{{ $categories[$category][0] }} notifications" />
