{{--
    A 2pt ring in dashes of $lane's colour, as the web's `border-2 border-dashed rounded-full`, $size across. $rounded
    makes it a rounded square instead, around an organization.
--}}
@php($rounded ??= 'rounded-full')
<native:column :width="$size" :height="$size" class="absolute top-0 left-0 {{ $rounded }} border-2 border-dashed border-theme-{{ \App\Devnull\Lane::token($lane) }}" />
