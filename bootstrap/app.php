<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        api: __DIR__ . '/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'subscription.check' => \App\Http\Middleware\CheckSubscription::class,
            'reserva.online' => \App\Http\Middleware\RequiereReservaOnline::class,
            'reservas.creacion' => \App\Http\Middleware\RequiereCreacionHabilitada::class,
            'reservas.device' => \App\Http\Middleware\ExigeDeviceToken::class,
            'admin.activo' => \App\Http\Middleware\RequiereAdminActivo::class,
            'portal.pro' => \App\Http\Middleware\PortalProfesionalesHabilitado::class,
            // Sanctum ability checks (none were registered before the portal).
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);

        // Route middleware runs in the order of the framework's priority list,
        // NOT in the order written on the route: authentication would otherwise
        // run before the kill switch and answer 401 instead of 404 while the
        // portal is disabled. Pin the kill switch ahead of authentication.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\PortalProfesionalesHabilitado::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();