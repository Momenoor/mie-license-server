<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\License;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The two endpoints a MIE installation calls: once to activate a freshly
 * entered key during its own installer, and periodically afterward to
 * confirm the key is still good. Both share the same license lookup and
 * validity checks — only what happens to the activation row differs
 * (create-or-touch vs. touch-only).
 */
class LicenseController extends Controller
{
    public function activate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'license_key' => ['required', 'string'],
            'fingerprint' => ['required', 'string'],
            'domain' => ['nullable', 'string'],
            'app_version' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['valid' => false, 'reason' => 'invalid_request'], 422);
        }

        $data = $validator->validated();
        $license = $this->findLicense($data['license_key']);

        if (! $license) {
            return $this->invalid('not_found');
        }

        if ($failure = $this->failureReason($license)) {
            return $this->invalid($failure);
        }

        $activation = $license->activations()->where('fingerprint', $data['fingerprint'])->first();

        if (! $activation && $license->activations()->count() >= $license->max_activations) {
            return $this->invalid('activation_limit_reached');
        }

        $license->activations()->updateOrCreate(
            ['fingerprint' => $data['fingerprint']],
            [
                'domain' => $data['domain'] ?? null,
                'ip_address' => $request->ip(),
                'app_version' => $data['app_version'] ?? null,
                'first_seen_at' => $activation?->first_seen_at ?? now(),
                'last_seen_at' => now(),
            ],
        );

        return $this->valid($license);
    }

    public function verify(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'license_key' => ['required', 'string'],
            'fingerprint' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['valid' => false, 'reason' => 'invalid_request'], 422);
        }

        $data = $validator->validated();
        $license = $this->findLicense($data['license_key']);

        if (! $license) {
            return $this->invalid('not_found');
        }

        if ($failure = $this->failureReason($license)) {
            return $this->invalid($failure);
        }

        // Deliberately never creates an activation here — a fingerprint
        // with no prior activation has to go through activate() again,
        // so verify() can never be used to mint new activation slots.
        $activation = $license->activations()->where('fingerprint', $data['fingerprint'])->first();

        if (! $activation) {
            return $this->invalid('not_activated');
        }

        $activation->update(['last_seen_at' => now(), 'ip_address' => $request->ip()]);

        return $this->valid($license);
    }

    private function findLicense(string $plaintextKey): ?License
    {
        return License::query()
            ->where('key_hash', License::hashKey($plaintextKey))
            ->first();
    }

    private function failureReason(License $license): ?string
    {
        if ($license->status === 'revoked') {
            return 'revoked';
        }

        if ($license->status === 'suspended') {
            return 'suspended';
        }

        if ($license->isExpired()) {
            return 'expired';
        }

        return null;
    }

    private function valid(License $license): JsonResponse
    {
        return response()->json([
            'valid' => true,
            'expires_at' => $license->expires_at?->toIso8601String(),
            'plan' => $license->plan,
        ]);
    }

    private function invalid(string $reason): JsonResponse
    {
        return response()->json(['valid' => false, 'reason' => $reason], 200);
    }
}
