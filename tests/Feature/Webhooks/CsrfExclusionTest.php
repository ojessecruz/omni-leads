<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Router;

// CSRF is skipped while running tests, so a request would pass even with the check on.
// This asserts the exclusion itself, which broke once when the framework renamed the middleware.
test('webhooks externos não passam pela verificação de CSRF', function (string $name) {
    $router = app(Router::class);
    $route = $router->getRoutes()->getByName($name);

    $middleware = collect($router->gatherRouteMiddleware($route))
        ->filter(fn ($m) => is_string($m) && class_exists($m) && is_a($m, PreventRequestForgery::class, true));

    expect($middleware)->toBeEmpty();
})->with(['whatsapp.webhook', 'outbound.webhook']);
