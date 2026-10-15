<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('libera o painel do Horizon só para os e-mails em HORIZON_ADMINS', function () {
    config(['horizon.admins' => ['admin@omni.test']]);

    expect(Gate::forUser(User::factory()->make(['email' => 'admin@omni.test']))->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->make(['email' => 'outro@omni.test']))->allows('viewHorizon'))->toBeFalse();
});
