<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReleaseIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Where the app repository's GitHub Action announces a new release tag.
 * Authenticated with a shared token (config('releases.api_token')), never
 * with a license key.
 */
class ReleaseController extends Controller
{
    public function store(Request $request, ReleaseIntake $intake): JsonResponse
    {
        $token = (string) config('releases.api_token');

        if ($token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'version' => ['required', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:65000'],
            'released_at' => ['nullable', 'date'],
        ]);

        try {
            ['release' => $release, 'created' => $created] = $intake->record(
                $data['version'],
                $data['notes'] ?? null,
                isset($data['released_at']) ? Carbon::parse($data['released_at']) : null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'version' => $release->version,
            'is_published' => $release->is_published,
            'created' => $created,
        ], $created ? 201 : 200);
    }
}
