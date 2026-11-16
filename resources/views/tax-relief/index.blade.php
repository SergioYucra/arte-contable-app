<x-app-layout :title="__('Calculadora de alivio tributario')">
    <x-slot name="header">
        <div>
            <h1 class="font-semibold text-lg sm:text-xl text-foreground leading-tight">Calculadora de alivio tributario</h1>
            <p class="text-xs text-muted mt-0.5">Ley 1733: cuánto pagarías por una deuda con impuestos al contado y cuánto en cuotas.</p>
        </div>
    </x-slot>

    <div class="py-3 sm:py-6">
        <div class="w-full px-2.5 sm:px-6 lg:px-8">
            <div
                data-vue-island="TaxReliefCalculator"
                data-props="{{ json_encode($props) }}"
            ></div>
        </div>
    </div>
</x-app-layout>
