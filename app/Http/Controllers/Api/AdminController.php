<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function renewSubscription(Request $request, User $user): JsonResponse
    {
        if ($request->header('X-Admin-Secret') !== config('app.admin_secret')) {
            return response()->json(['error' => 'No autorizado'], 401);
        }

        $subscription = $user->subscription;

        if (!$subscription) {
            return response()->json(['error' => 'El usuario no tiene suscripción'], 404);
        }

        $subscription->update([
            'ends_at' => now()->addDays(30),
            'status'  => 'ACTIVO',
        ]);

        return response()->json([
            'message' => 'Suscripción renovada correctamente',
            'user_id' => $user->id,
            'ends_at' => $subscription->fresh()->ends_at,
        ]);
    }
}