<x-filament-panels::page>
    {{-- Plain submit button, not getCachedFormActions(): that helper does not
         exist on a custom Page in this Filament version and fataled the page. --}}
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex justify-end">
            <x-filament::button type="submit">
                Save changes
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
