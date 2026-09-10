<div class="space-y-2">
    <flux:input
        wire:model="tagInput.{{ $field }}"
        wire:keydown.enter.prevent="addTag('{{ $field }}')"
        label="{{ $label }}"
        placeholder="{{ $placeholder }}"
    >
        <x-slot name="iconTrailing">
            <flux:button size="sm" variant="subtle" icon="plus" class="-mr-1" wire:click="addTag('{{ $field }}')" :aria-label="__('Add')" />
        </x-slot>
    </flux:input>
    <flux:error name="draft.{{ $field }}" />
    @if ($draft[$field])
        <div class="flex flex-wrap gap-1.5">
            @foreach ($draft[$field] as $i => $tag)
                <span class="chip" wire:key="{{ $field }}-{{ $i }}">
                    {{ $tag }}
                    <button type="button" wire:click="removeTag('{{ $field }}', {{ $i }})" class="-mr-1 text-zinc-500 hover:text-white" aria-label="{{ __('Remove') }}">×</button>
                </span>
            @endforeach
        </div>
    @endif
</div>
