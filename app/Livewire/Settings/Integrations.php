<?php

namespace App\Livewire\Settings;

use App\Domains\Integrations\Exceptions\IntegrationException;
use App\Domains\Integrations\IntegrationVerifier;
use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\OutboundConnection;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Integrations')]
class Integrations extends Component
{
    /**
     * Field values per provider. Secret fields always start empty: a saved key is never sent back to the browser.
     *
     * @var array<string, array<string, string>>
     */
    public array $form = [];

    public function mount(): void
    {
        $saved = Auth::user()->integrationCredentials();

        foreach (IntegrationProvider::cases() as $provider) {
            foreach ($provider->fields() as $name => $field) {
                $this->form[$provider->value][$name] = $field['secret'] ? '' : (string) ($saved[$provider->value][$name] ?? '');
            }
        }
    }

    public function save(string $provider, IntegrationVerifier $verifier): void
    {
        $provider = $this->provider($provider);
        $user = Auth::user();
        $current = $user->credentialsFor($provider);

        $rules = [];
        foreach ($provider->fields() as $name => $field) {
            // Blank secret on a connected provider keeps the saved key (e.g. only the instance changed).
            $keepsSecret = $field['secret'] && filled($current[$name] ?? null);
            $rules["form.{$provider->value}.{$name}"] = [$keepsSecret ? 'nullable' : 'required', 'string', 'max:500', ...($name === 'base_url' ? ['url:http,https'] : [])];
        }
        $this->validate($rules, [], collect($provider->fields())->mapWithKeys(fn ($f, $name) => ["form.{$provider->value}.{$name}" => $f['label']])->all());

        $credentials = [];
        foreach (array_keys($provider->fields()) as $name) {
            $value = trim($this->form[$provider->value][$name] ?? '');
            $credentials[$name] = $value !== '' ? $value : (string) ($current[$name] ?? '');
        }

        try {
            $verifier->verify($provider, $credentials);
        } catch (IntegrationException $e) {
            $field = array_key_last($provider->fields());
            $this->addError("form.{$provider->value}.{$field}", __('Could not connect: :reason', ['reason' => $e->getMessage()]));

            return;
        }

        $user->integrations()->updateOrCreate(['provider' => $provider], ['credentials' => $credentials, 'verified_at' => now()]);
        $user->unsetRelation('integrations');

        foreach ($provider->fields() as $name => $field) {
            if ($field['secret']) {
                $this->form[$provider->value][$name] = '';
            }
        }

        Flux::toast(variant: 'success', text: __(':provider connected.', ['provider' => $provider->label()]));
    }

    public function remove(string $provider): void
    {
        $provider = $this->provider($provider);
        $user = Auth::user();

        $user->integrations()->where('provider', $provider)->delete();
        $user->unsetRelation('integrations');

        // A cold-mail connector cannot work without the key: projects using it go back to "None".
        if (in_array($provider, IntegrationProvider::outbound(), true)) {
            OutboundConnection::query()
                ->where('connector', $provider->value)
                ->whereIn('project_id', $user->projects()->select('id'))
                ->delete();
        }

        $this->form[$provider->value] = array_map(fn () => '', $provider->fields());

        Flux::toast(text: __(':provider disconnected.', ['provider' => $provider->label()]));
    }

    private function provider(string $value): IntegrationProvider
    {
        Validator::make(['provider' => $value], ['provider' => ['required', Rule::enum(IntegrationProvider::class)]])->validate();

        return IntegrationProvider::from($value);
    }

    public function render(): View
    {
        return view('livewire.settings.integrations', [
            'groups' => collect(IntegrationProvider::cases())->groupBy(fn (IntegrationProvider $p) => $p->group()),
            'connected' => Auth::user()->integrations->keyBy(fn (Integration $i) => $i->provider->value),
        ]);
    }
}
