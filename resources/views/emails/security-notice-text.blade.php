{{-- Parte text/plain: el cliente de correo la muestra como texto, sin interpretar HTML ni Markdown, así que
     no se escapa (con {{ }} un nombre como O'Brien llegaría como O&#039;Brien). La parte HTML sí escapa todo. --}}
{!! config('app.name') !!}

{!! $heading !!}

@foreach ($lines as $line)
{!! $line !!}

@endforeach
@foreach ($details as $label => $value)
{!! $label !!}: {!! $value !!}
@endforeach
@if (! empty($actionUrl) && ! empty($actionText))

{!! $actionText !!}: {!! $actionUrl !!}
@endif
@if (! empty($footer))

{!! $footer !!}
@endif
