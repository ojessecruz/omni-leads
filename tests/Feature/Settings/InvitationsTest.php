<?php

use App\Livewire\Auth\AcceptInvitation;
use App\Livewire\Settings\Invitations;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    config(['app.admins' => ['admin@omni.test']]);
});

function admin(): User
{
    return User::factory()->create(['email' => 'Admin@omni.test']);
}

test('só e-mails em ADMIN_EMAILS são admins, sem diferenciar maiúsculas', function () {
    expect(admin()->isAdmin())->toBeTrue()
        ->and(User::factory()->create()->isAdmin())->toBeFalse();
});

test('a tela de convites é só para admins', function () {
    $this->actingAs(User::factory()->create())->get(route('invitations.index'))->assertForbidden();
    $this->actingAs(admin())->get(route('invitations.index'))->assertOk()->assertSee(__('Send invitation'));
});

test('o menu mostra "Convidar usuários" só para admins', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertDontSee(route('invitations.index'));
    $this->actingAs(admin())->get(route('dashboard'))->assertSee(route('invitations.index'));
});

test('admin convida um e-mail e o convite vai com o link de cadastro', function () {
    Mail::fake();
    $admin = admin();

    Livewire::actingAs($admin)->test(Invitations::class)
        ->set('email', ' Novo@Cliente.com ')
        ->call('invite')
        ->assertHasNoErrors()
        ->assertSet('email', '');

    $invitation = Invitation::sole();

    expect($invitation->email)->toBe('novo@cliente.com')
        ->and($invitation->invited_by)->toBe($admin->id)
        ->and($invitation->isPending())->toBeTrue();

    Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use ($invitation) {
        return $mail->hasTo('novo@cliente.com')
            && hash('sha256', $mail->token) === $invitation->token_hash
            && str_contains($mail->render(), route('invitations.accept', $mail->token));
    });
});

test('não convida quem já tem conta', function () {
    Mail::fake();
    User::factory()->create(['email' => 'ja@existe.com']);

    Livewire::actingAs(admin())->test(Invitations::class)
        ->set('email', 'ja@existe.com')
        ->call('invite')
        ->assertHasErrors('email');

    Mail::assertNothingSent();
});

test('reenviar troca o token e o link antigo para de funcionar', function () {
    Mail::fake();
    $invitation = Invitation::factory()->withToken('antigo')->create();

    Livewire::actingAs(admin())->test(Invitations::class)->call('resend', $invitation->id);

    expect(Invitation::findPending('antigo'))->toBeNull()
        ->and(Invitation::count())->toBe(1);
    Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail) => Invitation::findPending($mail->token)?->is($invitation));
});

test('revogar apaga o convite pendente', function () {
    $invitation = Invitation::factory()->create();

    Livewire::actingAs(admin())->test(Invitations::class)->call('revoke', $invitation->id);

    expect(Invitation::count())->toBe(0);
});

test('quem não é admin não consegue convidar pelo componente', function () {
    Livewire::actingAs(User::factory()->create())->test(Invitations::class)->assertForbidden();
});

test('o convidado cria a conta, já verificada, e entra logado', function () {
    Invitation::factory()->withToken('tok')->create(['email' => 'novo@cliente.com', 'locale' => 'pt_BR']);

    $this->get(route('invitations.accept', 'tok'))->assertOk()->assertSee('novo@cliente.com');

    Livewire::test(AcceptInvitation::class, ['token' => 'tok'])
        ->set('name', 'Novo Cliente')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('accept')
        ->assertRedirect(route('dashboard'));

    $user = User::where('email', 'novo@cliente.com')->sole();

    expect($user->name)->toBe('Novo Cliente')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->locale)->toBe('pt_BR')
        ->and(Invitation::sole()->accepted_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
});

test('link usado, expirado ou desconhecido não cria conta', function (string $state) {
    $factory = Invitation::factory()->withToken('tok');
    $state === 'unknown' ? null : $factory->{$state}()->create();

    $this->get(route('invitations.accept', 'tok'))->assertOk()->assertSee(__('This invitation is no longer valid'));

    Livewire::test(AcceptInvitation::class, ['token' => 'tok'])
        ->set('name', 'X')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('accept')
        ->assertStatus(410);

    expect(User::count())->toBe(0);
})->with(['accepted', 'expired', 'unknown']);
