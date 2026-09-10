<?php

use App\Livewire\Projects\Index;
use App\Models\Icp;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

test('lista só os projetos do usuário', function () {
    $user = User::factory()->create();
    $mine = Project::factory()->for($user)->create(['name' => 'Meu projeto']);
    Project::factory()->create(['name' => 'Projeto alheio']);

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertOk()
        ->assertSee('Meu projeto')
        ->assertDontSee('Projeto alheio');

    $this->actingAs($user)->get(route('projects.show', $mine))->assertOk();
});

test('cria projeto e redireciona para o wizard', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Index::class)
        ->set('name', 'Agenda clínicas')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect(route('icps.create', $user->projects()->sole()));
});

test('página do ICP exige dono do projeto', function () {
    $icp = Icp::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('icps.show', $icp))->assertForbidden();
    $this->actingAs($icp->project->user)->get(route('icps.show', $icp))->assertOk()->assertSee($icp->name);
});
