{{--
    A 2pt ring in dashes of $lane's colour, as the web's `border-2 border-dashed rounded-full`, $size across.
--}}
<native:column :width="$size" :height="$size" class="absolute top-0 left-0 rounded-full border-2 border-dashed border-theme-{{ \App\Devnull\Lane::token($lane) }}" />
