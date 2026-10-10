<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Services\TestLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Laravel\Passkeys\Support\WebAuthn;

/**
 * Bootstrap awal tanpa password.
 *
 * Di deployment passwordless yang tertutup, belum ada satu pun akun yang bisa
 * login: kredensial pertama harus didaftarkan oleh manusia yang memegang
 * mesinnya. Rute ini mengisi langkah itu TANPA menjadi pintu belakang password:
 *
 *   - Hanya aktif ketika aplikasi BELUM punya user sama sekali (cek `doesntExist`).
 *   - Begitu ada user, rute ini 404 selamanya.
 *   - Di luar environment `local`, rute ini hanya bisa diakses bila BOOTSTRAP_KEY
 *     disetel dan cocok. Bila tidak disetel, rute gagal-tertutup (404) — jadi
 *     bootstrap yang terlupakan tidak pernah menyerahkan akun admin ke publik.
 *
 * Ini pola "installer sekali jalan" (mirip /install WordPress), bukan fallback
 * autentikasi: tidak ada password yang dibuat, dan hanya sidik jari yang dipakai.
 */
class BootstrapController extends Controller
{
    public const SESSION_KEY = 'bootstrap.user_id';

    public function __construct(private readonly TestLogger $logger) {}

    public function show(Request $request): View
    {
        $this->assertReachable($request);

        return view('auth.bootstrap');
    }

    /**
     * Buat akun admin pertama (tanpa password) dan terbitkan opsi registrasi.
     */
    public function options(
        Request $request,
        GenerateRegistrationOptions $generate,
    ): JsonResponse {
        $this->assertReachable($request);

        $data = $request->validate([
            'username' => ['required', 'string', 'max:64', 'alpha_dash'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        // Balapan dua tab bootstrap ditolak dengan cara yang sama seperti user
        // yang sudah ada: rute tidak lagi layak dipakai.
        if (User::query()->exists()) {
            abort(404);
        }

        $admin = User::create([
            'username' => $data['username'],
            'name' => $data['name'],
            'email' => null,
            'password' => null,
            'role' => Role::Admin,
        ]);

        $request->session()->put(self::SESSION_KEY, $admin->getKey());

        $options = $generate($admin);
        $request->session()->put('passkey.registration_options', WebAuthn::toJson($options));

        return response()->json([
            'options' => WebAuthn::toBrowserArray($options),
        ]);
    }

    public function store(
        Request $request,
        PasskeyRegistrationRequest $formRequest,
        StorePasskey $storePasskey,
    ): JsonResponse {
        $adminId = $request->session()->get(self::SESSION_KEY);
        $admin = $adminId ? User::find($adminId) : null;

        abort_unless($admin instanceof User, 409, 'Sesi bootstrap tidak ditemukan. Mulai ulang dari /bootstrap.');
        abort_unless($admin->role === Role::Admin, 409);
        // Sekali pakai: begitu admin punya passkey, jalur ini mati.
        abort_if($admin->passkeys()->exists(), 404);

        $name = $formRequest->string('name')->toString() ?: 'Windows Hello — admin';

        $passkey = $storePasskey(
            $admin,
            $name,
            $formRequest->credential(),
            $formRequest->registrationOptions(),
        );

        $this->logger->log('bootstrap_passkey_enrolled', [
            'request' => $request,
            'user' => $admin,
            'auth_method' => 'passkey',
            'credential_id' => $passkey->credential_id,
            'meta' => ['passkey_name' => $passkey->name],
        ]);

        $request->session()->forget(self::SESSION_KEY);

        return response()->json([
            'ok' => true,
            'redirect' => route('login'),
        ]);
    }

    private function assertReachable(Request $request): void
    {
        // Sudah ada user => instalasi sudah dibootstrap.
        abort_if(User::query()->exists(), 404);

        if (app()->environment('local')) {
            return;
        }

        $key = (string) config('auth.bootstrap_key', '');

        // Di luar local: gagal-tertutup bila kunci tidak disetel.
        abort_unless($key !== '' && hash_equals($key, (string) $request->query('key', '')), 404);
    }
}
