{{-- Your own modal (here a plain <dialog>) holding the package's filter fields. --}}
<div x-data>
    <button type="button" class="my-filter-button" x-on:click="$refs.mine.showModal()">Filter orders</button>
    <dialog class="my-modal" x-ref="mine" wire:ignore.self>
        @include('acun-datatable::livewire.filter-panel')
    </dialog>
</div>
