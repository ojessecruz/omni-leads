<?php

namespace App\Livewire\Settings;

use App\Actions\WhatsApp\RegisterWhatsAppWebhook;
use App\Domains\Enrichment\Clients\EvolutionClient;
use App\Domains\Integrations\Exceptions\IntegrationException;
use App\Domains\Integrations\IntegrationVerifier;
use App\Domains\Integrations\WhatsAppPairing;
use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\OutboundConnection;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

#[Title('Integrations')]
class Integrations extends Component
{
    /**
     * Field values per provider. Secret fields always start empty: a saved key is never sent back to the browser.
     *
     * @var array<string, array<string, string>>
     */
    public array $form = [];

    /** QR code being shown while the user pairs their phone with Evolution. */
    public ?string $whatsappQr = null;

    public int $pairingPolls = 0;

    /** Provider whose settings panel is open; ?open=google_places links straight to it. */
    #[Url(as: 'open', except: '')]
    public string $open = '';

    public bool $showEditor = false;

    public function mount(): void
    {
        $user = Auth::user();

        foreach (IntegrationProvider::cases() as $provider) {
            $saved = $user->integration($provider)->credentials ?? [];

            foreach ($provider->fields() as $name => $field) {
                $this->form[$provider->value][$name] = $field['secret'] ? '' : (string) ($saved[$name] ?? '');
            }
        }

        // Omni Leads' server is the default when it exists and the user has not brought their own.
        $evolution = $user->integration(IntegrationProvider::Evolution);
        $this->form['evolution']['mode'] = $evolution
            ? ($evolution->credentials['mode'] ?? 'own')
            : (app(EvolutionClient::class)->hostedAvailable() ? 'hosted' : 'own');

        $this->open = IntegrationProvider::tryFrom($this->open)->value ?? '';
        $this->showEditor = $this->open !== '';
    }

    public function edit(string $provider): void
    {
        $this->open = $this->provider($provider)->value;
        $this->showEditor = true;
        $this->resetErrorBag();
    }

    /** The panel was closed (button, Esc or backdrop). */
    public function updatedShowEditor(bool $value): void
    {
        if (! $value) {
            $this->open = '';
            $this->whatsappQr = null;
        }
    }

    public function save(string $provider, IntegrationVerifier $verifier): void
    {
        $provider = $this->provider($provider);
        $user = Auth::user();
        $current = $user->integration($provider)->credentials ?? [];

        if ($provider === IntegrationProvider::Evolution && ($this->form['evolution']['mode'] ?? 'own') === 'hosted') {
            $this->useHostedEvolution($current);

            return;
        }

        $rules = [];
        foreach ($provider->fields() as $name => $field) {
            // Blank secret on a connected provider keeps the saved key (e.g. only the server URL changed).
            $keepsSecret = $field['secret'] && filled($current[$name] ?? null);
            $rules["form.{$provider->value}.{$name}"] = [$keepsSecret ? 'nullable' : 'required', 'string', 'max:500', ...($name === 'base_url' ? ['url:http,https'] : [])];
        }
        $this->validate($rules, [], collect($provider->fields())->mapWithKeys(fn ($f, $name) => ["form.{$provider->value}.{$name}" => $f['label']])->all());

        $credentials = [];
        foreach (array_keys($provider->fields()) as $name) {
            $value = trim($this->form[$provider->value][$name] ?? '');
            $credentials[$name] = $value !== '' ? $value : (string) ($current[$name] ?? '');
        }

        $sameServer = true;
        if ($provider === IntegrationProvider::Evolution) {
            // The paired phone lives on that server: keep it only while the server stays the same.
            $sameServer = ($current['mode'] ?? 'own') === 'own' && rtrim($current['base_url'] ?? '', '/') === rtrim($credentials['base_url'], '/');
            $credentials = ['mode' => 'own', ...$credentials, ...($sameServer ? array_diff_key($current, $credentials, ['mode' => true]) : [])];
        }

        try {
            $verifier->verify($provider, $credentials);
        } catch (IntegrationException $e) {
            $field = array_key_last($provider->fields());
            $this->addError("form.{$provider->value}.{$field}", __('Could not connect: :reason', ['reason' => $e->getMessage()]));

            return;
        }

        if ($provider === IntegrationProvider::Evolution && ! $sameServer) {
            $this->forgetPairedPhone();
        }

        $user->integrations()->updateOrCreate(['provider' => $provider], ['credentials' => $credentials, 'verified_at' => now()]);
        $user->unsetRelation('integrations');

        foreach ($provider->fields() as $name => $field) {
            if ($field['secret']) {
                $this->form[$provider->value][$name] = '';
            }
        }

        // Evolution stays open: the phone still has to be paired.
        if ($provider !== IntegrationProvider::Evolution) {
            $this->showEditor = false;
            $this->open = '';
        }

        Flux::toast(variant: 'success', text: $provider === IntegrationProvider::Evolution && ! $provider->isUsable($credentials)
            ? __('Evolution server connected. Now connect your phone.')
            : __(':provider connected.', ['provider' => $provider->label()]));
    }

    /** @param  array<string, string>  $current */
    protected function useHostedEvolution(array $current): void
    {
        if (! app(EvolutionClient::class)->hostedAvailable()) {
            $this->addError('form.evolution.mode', __('Omni Leads does not offer a WhatsApp server here. Use your own.'));

            return;
        }

        $this->whatsappQr = null;

        // Coming from the user's own server: that instance is not on ours, start over.
        $sameServer = ($current['mode'] ?? 'own') === 'hosted';
        $credentials = $sameServer ? $current : ['mode' => 'hosted'];

        if (! $sameServer) {
            $this->forgetPairedPhone();
        }

        Auth::user()->integrations()->updateOrCreate(['provider' => IntegrationProvider::Evolution], ['credentials' => $credentials, 'verified_at' => now()]);
        Auth::user()->unsetRelation('integrations');

        Flux::toast(variant: 'success', text: __('Using the Omni Leads WhatsApp server. Now connect your phone.'));
    }

    /** Deletes the instance on the server being left, so no orphan stays paired there. */
    protected function forgetPairedPhone(): void
    {
        if ($integration = Auth::user()->integration(IntegrationProvider::Evolution)) {
            app(WhatsAppPairing::class)->forget($integration);
        }
    }

    public function connectPhone(WhatsAppPairing $pairing): void
    {
        $integration = Auth::user()->integration(IntegrationProvider::Evolution);

        if ($integration === null) {
            return;
        }

        try {
            $this->whatsappQr = $pairing->start($integration);
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: __('Could not connect: :reason', ['reason' => $e->getMessage()]));

            return;
        }

        $this->pairingPolls = 0;

        if ($this->whatsappQr === null && $integration->provider->isUsable($integration->credentials)) {
            $this->registerWebhook($integration);
            Flux::toast(variant: 'success', text: __('WhatsApp connected.'));
        }
    }

    /** Polled every 3s while the QR code is on screen; the QR is renewed every ~30s. */
    public function checkPhone(WhatsAppPairing $pairing): void
    {
        $integration = Auth::user()->integration(IntegrationProvider::Evolution);

        if ($integration === null || $this->whatsappQr === null) {
            $this->whatsappQr = null;

            return;
        }

        try {
            if ($pairing->check($integration)) {
                $this->whatsappQr = null;
                $this->registerWebhook($integration);
                Flux::toast(variant: 'success', text: __('WhatsApp connected.'));

                return;
            }

            if (++$this->pairingPolls % 10 === 0) {
                $this->whatsappQr = $pairing->refresh($integration) ?? $this->whatsappQr;
            }
        } catch (RuntimeException $e) {
            $this->whatsappQr = null;
            Flux::toast(variant: 'danger', text: __('Could not connect: :reason', ['reason' => $e->getMessage()]));
        }
    }

    /** Best effort: whatsapp:register-webhooks retries every hour. */
    protected function registerWebhook(Integration $integration): void
    {
        try {
            app(RegisterWhatsAppWebhook::class)($integration);
        } catch (RuntimeException $e) {
            report($e);
        }
    }

    /** Sends the inbox webhook to Evolution again and shows the error, if any. */
    public function registerInboxWebhook(RegisterWhatsAppWebhook $register): void
    {
        $integration = Auth::user()->integration(IntegrationProvider::Evolution);

        if ($integration === null) {
            return;
        }

        try {
            $register($integration, force: true)
                ? Flux::toast(variant: 'success', text: __('Inbox webhook registered.'))
                : Flux::toast(variant: 'danger', text: __('The phone must be paired, and the app reachable from the internet.'));
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: __('Could not register the webhook: :reason', ['reason' => $e->getMessage()]));
        }

        Auth::user()->unsetRelation('integrations');
    }

    public function cancelPairing(): void
    {
        $this->whatsappQr = null;
    }

    public function disconnectPhone(WhatsAppPairing $pairing): void
    {
        $integration = Auth::user()->integration(IntegrationProvider::Evolution);

        if ($integration === null) {
            return;
        }

        try {
            $pairing->unpair($integration);
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: __('Could not disconnect: :reason', ['reason' => $e->getMessage()]));

            return;
        }

        Flux::toast(text: __('WhatsApp disconnected.'));
    }

    public function remove(string $provider, WhatsAppPairing $pairing): void
    {
        $provider = $this->provider($provider);
        $user = Auth::user();

        if ($provider === IntegrationProvider::Evolution && ($integration = $user->integration($provider))) {
            $pairing->forget($integration);
            $this->whatsappQr = null;
        }

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
        if ($provider === IntegrationProvider::Evolution) {
            $this->form['evolution']['mode'] = app(EvolutionClient::class)->hostedAvailable() ? 'hosted' : 'own';
        }

        Flux::toast(text: __(':provider disconnected.', ['provider' => $provider->label()]));
    }

    private function provider(string $value): IntegrationProvider
    {
        Validator::make(['provider' => $value], ['provider' => ['required', Rule::enum(IntegrationProvider::class)]])->validate();

        return IntegrationProvider::from($value);
    }

    /**
     * What each tile says: ok (green), attention (amber) or idle.
     *
     * @return array{tone: 'ok'|'warn'|'idle', label: string}
     */
    protected function tileStatus(IntegrationProvider $provider, ?Integration $integration): array
    {
        $credentials = $integration->credentials ?? [];

        return match (true) {
            $integration === null && $provider->required() => ['tone' => 'warn', 'label' => __('Required')],
            $integration === null => ['tone' => 'idle', 'label' => __('Set up')],
            $provider === IntegrationProvider::Evolution && ! $provider->isUsable($credentials) => ['tone' => 'warn', 'label' => __('Phone not connected')],
            $provider === IntegrationProvider::Evolution && filled($credentials['webhook_error'] ?? null) => ['tone' => 'warn', 'label' => __('Inbox not receiving')],
            default => ['tone' => 'ok', 'label' => __('Connected')],
        };
    }

    public function render(): View
    {
        $connected = Auth::user()->integrations->keyBy(fn (Integration $i) => $i->provider->value);

        return view('livewire.settings.integrations', [
            'groups' => collect(IntegrationProvider::cases())->groupBy(fn (IntegrationProvider $p) => $p->group()),
            'statuses' => collect(IntegrationProvider::cases())->mapWithKeys(fn (IntegrationProvider $p) => [$p->value => $this->tileStatus($p, $connected->get($p->value))]),
            'editing' => IntegrationProvider::tryFrom($this->open),
            'connected' => $connected,
            'whatsapp' => Auth::user()->integration(IntegrationProvider::Evolution)?->credentials,
            'hostedWhatsApp' => app(EvolutionClient::class)->hostedAvailable(),
        ]);
    }
}
