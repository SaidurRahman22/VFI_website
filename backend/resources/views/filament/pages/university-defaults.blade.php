<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="fi-form-actions mt-6">
            <x-filament::actions :actions="$this->getCachedFormActions()" />
        </div>
    </form>
</x-filament-panels::page>
