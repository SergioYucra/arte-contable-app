@php
    $customLogo = config('brand.logo');
    $detectedImage = null;

    if ($customLogo && file_exists(public_path($customLogo))) {
        $detectedImage = $customLogo;
    } elseif (file_exists(public_path('images/logo_contable.png'))) {
        $detectedImage = 'images/logo_contable.png';
    } elseif (file_exists(public_path('images/logo.svg'))) {
        $detectedImage = 'images/logo.svg';
    } elseif (file_exists(public_path('images/logo.png'))) {
        $detectedImage = 'images/logo.png';
    } elseif (file_exists(public_path('images/logo.webp'))) {
        $detectedImage = 'images/logo.webp';
    }
@endphp

@if ($detectedImage)
    <img src="{{ asset($detectedImage) }}" alt="{{ config('brand.name', 'El Arte de la Contabilidad') }}" {{ $attributes->merge(['class' => 'object-contain inline-block']) }}>
@else
    <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" {{ $attributes }}>
        {{-- Emblema oficial adaptativo: Libro contable abierto con pilares de balance financiero --}}
        <defs>
            <linearGradient id="artLogoGrad" x1="4" y1="6" x2="44" y2="42" gradientUnits="userSpaceOnUse">
                <stop offset="0%" stop-color="currentColor" stop-opacity="1" />
                <stop offset="100%" stop-color="currentColor" stop-opacity="0.75" />
            </linearGradient>
        </defs>

        {{-- Lomo y base del libro --}}
        <path d="M24 38C20 35.5 13 35 6 37V11C13 9 20 9.5 24 12C28 9.5 35 9 42 11V37C35 35 28 35.5 24 38Z" 
              stroke="url(#artLogoGrad)" 
              stroke-width="2.5" 
              stroke-linecap="round" 
              stroke-linejoin="round" />

        {{-- Separador central del libro (columna vertebral) --}}
        <path d="M24 12V38" 
              stroke="url(#artLogoGrad)" 
              stroke-width="2.5" 
              stroke-linecap="round" />

        {{-- Líneas de asientos contables (página izquierda) --}}
        <path d="M11 18H19M11 23H17M11 28H19" 
              stroke="currentColor" 
              stroke-width="1.75" 
              stroke-linecap="round" 
              stroke-opacity="0.8" />

        {{-- Columnas de debe y haber / balance gráfico (página derecha) --}}
        <path d="M29 28V24M33 28V19M37 28V16" 
              stroke="currentColor" 
              stroke-width="2" 
              stroke-linecap="round" />

        {{-- Insignia circular de precisión contable en el ápice --}}
        <circle cx="24" cy="7" r="2.5" fill="currentColor" />
    </svg>
@endif

