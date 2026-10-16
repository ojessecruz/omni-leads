<x-mail::message>
<p class="eyebrow">{{ __('Invitation') }}</p>

# {{ __('You are invited to Omni Leads') }}

{{ __(':name invited you to use Omni Leads: describe your ideal customer and get a scored list of leads, enriched with email and WhatsApp.', ['name' => $inviter]) }}

<x-mail::button :url="$url">
{{ __('Create my account') }}
</x-mail::button>

{{ __('The link works once and expires in :days days.', ['days' => $days]) }}

<x-slot:subcopy>
{{ __('If the button does not work, copy this link into your browser:') }} <span class="break-all">[{{ $url }}]({{ $url }})</span><br>
{{ __('Not expecting this? You can ignore this email.') }}
</x-slot:subcopy>
</x-mail::message>
