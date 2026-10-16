<?php

use App\Domains\WhatsApp\FirstContactSchedule;

/** Unix time of a São Paulo wall-clock moment. */
function sp(string $local): int
{
    return (new DateTimeImmutable($local, new DateTimeZone('America/Sao_Paulo')))->getTimestamp();
}

function weekdaySchedule(int $perHour = 8, array $perDay = [0 => 10, 3 => 20, 7 => 30]): FirstContactSchedule
{
    return new FirstContactSchedule($perHour, $perDay, 'America/Sao_Paulo', ['days' => [1, 2, 3, 4, 5], 'from' => 9, 'until' => 18], 3);
}

test('dentro do horário comercial o envio sai no momento pedido', function () {
    $now = sp('2026-10-19 10:00'); // Monday

    expect(weekdaySchedule()->slot($now, $now + 300, [], sp('2026-09-01')))->toBe($now + 300);
});

test('fora do horário comercial o envio espera a próxima abertura', function () {
    $schedule = weekdaySchedule();
    $paired = sp('2026-09-01');

    expect($schedule->slot(sp('2026-10-19 19:30'), sp('2026-10-19 19:30'), [], $paired))->toBe(sp('2026-10-20 09:00'))
        ->and($schedule->slot(sp('2026-10-16 17:59'), sp('2026-10-16 18:02'), [], $paired))->toBe(sp('2026-10-19 09:00'))
        ->and($schedule->slot(sp('2026-10-17 11:00'), sp('2026-10-17 11:00'), [], $paired))->toBe(sp('2026-10-19 09:00'));
});

test('limite por hora empurra o envio até a janela de uma hora liberar', function () {
    $now = sp('2026-10-19 10:00');
    $taken = [sp('2026-10-19 09:10'), sp('2026-10-19 09:20'), sp('2026-10-19 09:30')];

    expect(weekdaySchedule(perHour: 3)->slot($now, $now, $taken, sp('2026-09-01')))->toBe(sp('2026-10-19 10:10'));
});

test('limite diário aquece com os dias desde o pareamento', function () {
    $schedule = weekdaySchedule(perHour: 100, perDay: [0 => 2, 3 => 5]);
    $now = sp('2026-10-19 10:00');
    $taken = [sp('2026-10-19 09:00'), sp('2026-10-19 09:30')];

    // Paired today: 2 per day, so the next one goes tomorrow at opening.
    expect($schedule->slot($now, $now, $taken, sp('2026-10-19 08:00')))->toBe(sp('2026-10-20 09:00'))
        // Paired a week ago: 5 per day, still room today.
        ->and($schedule->slot($now, $now, $taken, sp('2026-10-12 08:00')))->toBe($now)
        ->and($schedule->dailyCap($now, sp('2026-10-16 08:00')))->toBe(5);
});

test('fila cheia além do horizonte devolve null e o envio imediato respeita os limites', function () {
    $schedule = weekdaySchedule(perHour: 100, perDay: [0 => 1]);
    $now = sp('2026-10-19 10:00');
    $taken = [sp('2026-10-19 09:00'), sp('2026-10-20 09:00'), sp('2026-10-21 09:00'), sp('2026-10-22 09:00')];

    expect($schedule->slot($now, $now, $taken, sp('2026-09-01')))->toBeNull()
        ->and($schedule->allowsNow($now, [sp('2026-10-19 09:00')], sp('2026-09-01')))->toBeFalse()
        ->and($schedule->allowsNow($now, [], sp('2026-09-01')))->toBeTrue()
        ->and($schedule->allowsNow(sp('2026-10-19 20:00'), [], sp('2026-09-01')))->toBeFalse();
});
