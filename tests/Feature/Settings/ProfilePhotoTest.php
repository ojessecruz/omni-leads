<?php

use App\Livewire\Settings\Profile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    config(['filesystems.default' => 'r2', 'filesystems.disks.r2.url' => 'https://media.omni.test']);
    Storage::fake('r2');
    Storage::fake('local');
});

test('o R2 é o disco padrão da aplicação', function () {
    expect(require config_path('filesystems.php'))
        ->toHaveKey('disks.r2.driver', 's3')
        ->and(config('livewire.temporary_file_upload.disk'))->toBe('local');
});

test('enviar foto salva no disco padrão e troca a anterior', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(Profile::class)
        ->set('photo', UploadedFile::fake()->image('eu.jpg', 300, 300))
        ->assertHasNoErrors();

    $first = $user->fresh()->avatar_path;
    expect($first)->toStartWith("avatars/{$user->id}/");
    Storage::disk('r2')->assertExists($first);

    $component->set('photo', UploadedFile::fake()->image('nova.png', 300, 300))->assertHasNoErrors();

    $second = $user->fresh()->avatar_path;
    expect($second)->not->toBe($first);
    Storage::disk('r2')->assertMissing($first);
    Storage::disk('r2')->assertExists($second);
});

test('recusa arquivo que não é imagem ou grande demais', function (UploadedFile $file) {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Profile::class)
        ->set('photo', $file)
        ->assertHasErrors('photo');

    expect($user->fresh()->avatar_path)->toBeNull();
})->with([
    'pdf' => fn () => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
    'acima de 2 MB' => fn () => UploadedFile::fake()->image('grande.jpg', 800, 800)->size(3000),
]);

test('remover a foto apaga o arquivo e volta para as iniciais', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user)->test(Profile::class)->set('photo', UploadedFile::fake()->image('eu.jpg', 300, 300));
    $path = $user->fresh()->avatar_path;

    Livewire::actingAs($user->fresh())->test(Profile::class)->call('removePhoto');

    expect($user->fresh()->avatar_path)->toBeNull()
        ->and($user->fresh()->avatarUrl())->toBeNull();
    Storage::disk('r2')->assertMissing($path);
});

test('o header mostra a foto quando existe', function () {
    $user = User::factory()->create(['name' => 'Ana Souza']);
    $user->forceFill(['avatar_path' => 'avatars/1/foto.jpg'])->save();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertSee($user->avatarUrl(), false);
});

test('se o storage falhar, mostra erro em vez de quebrar a página', function () {
    $user = User::factory()->create();
    // Real R2 driver pointed at a closed port, as when production has no reachable bucket/keys.
    config(['filesystems.disks.r2' => [...config('filesystems.disks.r2'), 'key' => 'x', 'secret' => 'x', 'bucket' => 'b', 'endpoint' => 'http://127.0.0.1:1']]);
    Storage::forgetDisk('r2');

    Livewire::actingAs($user)->test(Profile::class)
        ->set('photo', UploadedFile::fake()->image('eu.jpg', 300, 300))
        ->assertHasErrors('photo');

    expect($user->fresh()->avatar_path)->toBeNull();
});

test('no R2 tudo fica dentro da pasta omnileads do bucket', function () {
    $disk = Storage::build([...config('filesystems.disks.r2'), 'key' => 'x', 'secret' => 'x', 'bucket' => 'compartilhado', 'endpoint' => 'https://conta.r2.cloudflarestorage.com']);

    expect(config('filesystems.disks.r2.root'))->toBe('omnileads')
        ->and($disk->url('avatars/1/foto.jpg'))->toBe('https://media.omni.test/omnileads/avatars/1/foto.jpg')
        ->and($disk->temporaryUrl('avatars/1/foto.jpg', now()->addHour()))->toContain('/compartilhado/omnileads/avatars/1/foto.jpg');
});
