<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PairingCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    private function userPayload(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role];
    }

    /** POST /api/login - email + password -> bearer token (API clients, Bruno/Postman, tests). */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email', 'password' => 'required|string', 'device_name' => 'nullable|string|max:80',
        ]);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }
        if (! $user->is_active) {
            return response()->json(['message' => 'Account is deactivated.'], 403);
        }
        $token = $user->createToken($data['device_name'] ?? 'api-client', ['*'], now()->addDays(30))->plainTextToken;
        return response()->json(['data' => ['token' => $token, 'user' => $this->userPayload($user)]]);
    }

    /** POST /api/extension/pair - one-time pairing code (made in the student dashboard) -> extension token. */
    public function pair(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|min:6|max:16', 'device_name' => 'nullable|string|max:80']);
        $hash = hash('sha256', strtoupper(trim($data['code'])));
        $row = PairingCode::where('code_hash', $hash)->whereNull('used_at')->where('expires_at', '>', now())->first();
        if (! $row) {
            return response()->json(['message' => 'Pairing code is invalid or expired.'], 422);
        }
        $user = User::find($row->user_id);
        if (! $user || ! $user->is_active || $user->role !== 'student') {
            return response()->json(['message' => 'Pairing is only available for active student accounts.'], 403);
        }
        $row->update(['used_at' => now()]);
        $token = $user->createToken('extension:' . ($data['device_name'] ?? 'chrome'), ['extension'], now()->addDays(30))->plainTextToken;
        return response()->json(['data' => ['token' => $token, 'user' => $this->userPayload($user)]], 201);
    }

    /** POST /api/pairing-codes - student creates a 10-minute one-time code. */
    public function createPairingCode(Request $request): JsonResponse
    {
        $user = $request->user();
        PairingCode::where('user_id', $user->id)->whereNull('used_at')->delete();
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no look-alike characters
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $row = PairingCode::create(['user_id' => $user->id, 'code_hash' => hash('sha256', $code), 'expires_at' => now()->addMinutes(10)]);
        return response()->json(['data' => ['code' => $code, 'expires_at' => $row->expires_at]], 201);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
        return response()->json(['message' => 'Logged out.']);
    }

    /** GET /api/extension/devices - student sees paired extensions. */
    public function devices(Request $request): JsonResponse
    {
        $rows = $request->user()->tokens()->where('name', 'like', 'extension:%')
            ->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at']);
        return response()->json(['data' => $rows]);
    }

    public function revokeDevice(Request $request, int $id): JsonResponse
    {
        $n = $request->user()->tokens()->where('id', $id)->where('name', 'like', 'extension:%')->delete();
        return $n ? response()->json(['message' => 'Extension access revoked.']) : response()->json(['message' => 'Resource not found.'], 404);
    }
}
