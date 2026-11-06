{{-- P5: widget de Cloudflare Turnstile. No muestra nada si el captcha está desactivado (TURNSTILE_ENABLED=false). --}}
@if (\App\Rules\Turnstile::enabled())
    @once
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
    @endonce

    <div {{ $attributes->merge(['class' => 'min-h-[65px]']) }}>
        <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}" data-language="es" data-theme="auto" data-size="flexible"></div>
        <x-input-error :messages="$errors->first(\App\Rules\Turnstile::FIELD)" class="mt-1.5" />
    </div>
@endif
