<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Services\TestLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;

/**
 * Pendaftaran sidik jari lintas-pengguna ("IT mendaftarkan sidik jari karyawan").
 *
 * Endpoint bawaan paket (`/user/passkeys`) SELALU bekerja pada pengguna yang
 * sedang login, dan penghapusan menolak 403 bila passkey bukan miliknya. Karena
 * itu seorang admin tidak bisa mendaftarkan passkey karyawan lewat jalur itu.
 * Controller ini mengisi kekosongan tersebut:
 *
 *   - Hanya admin (role admin) yang boleh memakainya.
 *   - Target SELALU diambil dari database (User karyawan ber-role `employee`),
 *     tidak pernah dari data yang dikirim browser selain id yang divalidasi.
 *   - Karyawan dibuat tanpa password dan tanpa email yang bisa dipakai.
 *   - Ceremony WebAuthn divalidasi penuh oleh validator paket (StorePasskey);
 *     tidak ada kriptografi buatan sendiri di sini.
 *
 * Alur: admin membuat akun karyawan → `prepare` mengunci sesi ke karyawan itu →
 * opsi registrasi diterbitkan untuk karyawan itu → sidik jari ditempel di
 * perangkat karyawan → passkey tersimpan sebagai milik karyawan (bukan admin).
 */
class EmployeeEnrollmentController extends Controller
{
    /** Kunci sesi yang menandai karyawan mana yang sedang didaftarkan. */
    public const SESSION_KEY = 'passkey.enrollment_target';

    public function __construct(private readonly TestLogger $logger) {}

    public function index(Request $request): View
    {
        return view('employees.enroll', [
            'employees' => User::query()
                ->where('role', Role::Employee)
                ->with('passkeys')
                ->orderBy('username')
                ->get(),
            'pendingTargetId' => $request->session()->get(self::SESSION_KEY),
            'sessionFingerprint' => $this->logger->sessionFingerprint($request->session()->getId()),
        ]);
    }

    /**
     * Buat akun karyawan passwordless. Nol password, nol email yang bisa dipakai.
     */
    public function create(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('users', 'username')],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $employee = User::create([
            'username' => $data['username'],
            'name' => $data['name'],
            'email' => null,
            'password' => null,
            'role' => Role::Employee,
        ]);

        $this->logger->log('employee_created', [
            'request' => $request,
            'user' => $request->user(),
            'meta' => [
                'target_user_id' => $employee->getKey(),
                'target_username' => $employee->username,
            ],
        ]);

        return redirect()
            ->route('employees.index')
            ->with('status', "Karyawan \"{$employee->username}\" dibuat (tanpa password). Lanjutkan dengan mendaftarkan sidik jarinya.");
    }

    /**
     * Kunci sesi ke satu karyawan. Tindakan ini wajib dikonfirmasi (passkey.confirm).
     */
    public function prepare(Request $request, User $employee): RedirectResponse
    {
        $this->assertEmployee($employee);

        $request->session()->put(self::SESSION_KEY, $employee->getKey());

        return redirect()->route('employees.index');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('employees.index');
    }

    /**
     * Opsi registrasi WebAuthn untuk karyawan yang sedang dikunci di sesi.
     */
    public function options(Request $request, User $employee, GenerateRegistrationOptions $generate): JsonResponse
    {
        $this->assertTarget($request, $employee);

        $options = $generate($employee);

        // Disimpan di kunci sesi yang sama dengan paket, sehingga FormRequest
        // bawaan paket (`PasskeyRegistrationRequest`) dapat menariknya kembali
        // saat submit — anti-replay gratis.
        $request->session()->put('passkey.registration_options', WebAuthn::toJson($options));

        return response()->json([
            'options' => WebAuthn::toBrowserArray($options),
        ]);
    }

    /**
     * Simpan passkey karyawan. Validasi kriptografis dilakukan oleh StorePasskey.
     */
    public function storePasskey(
        Request $request,
        User $employee,
        PasskeyRegistrationRequest $formRequest,
        StorePasskey $storePasskey,
    ): JsonResponse {
        $this->assertTarget($request, $employee);

        $name = $formRequest->string('name')->toString();

        if ($name === '') {
            $name = 'Windows Hello — '.$employee->username;
        }

        $passkey = $storePasskey(
            $employee,
            $name,
            $formRequest->credential(),
            $formRequest->registrationOptions(),
        );

        $this->logger->log('employee_passkey_enrolled', [
            'request' => $request,
            'user' => $request->user(),
            'auth_method' => 'passkey',
            'credential_id' => $passkey->credential_id,
            'meta' => [
                'target_user_id' => $employee->getKey(),
                'target_username' => $employee->username,
                'passkey_name' => $passkey->name,
            ],
        ]);

        // Lepas kunci sesi: satu ceremony, satu karyawan.
        $request->session()->forget(self::SESSION_KEY);

        return response()->json([
            'ok' => true,
            'redirect' => route('employees.index'),
        ]);
    }

    /**
     * Cabut passkey. Admin boleh mencabut passkey karyawan; pemilik boleh
     * mencabut passkey dirinya sendiri. Selain itu 403.
     */
    public function revoke(Request $request, Passkey $passkey): JsonResponse
    {
        $owner = $passkey->user;
        $actor = $request->user();

        abort_unless($owner instanceof User, 404);

        $isOwner = $owner->getKey() === $actor->getKey();
        $isEmployeeCredential = $owner->role === Role::Employee;

        abort_unless($isOwner || $isEmployeeCredential, 403);

        $fingerprint = $this->logger->credentialFingerprint($passkey->credential_id);
        $targetId = $owner->getKey();
        $targetUsername = $owner->username;

        $passkey->delete();

        $this->logger->log('employee_passkey_revoked', [
            'request' => $request,
            'user' => $actor,
            'meta' => [
                'target_user_id' => $targetId,
                'target_username' => $targetUsername,
                'credential_fingerprint' => $fingerprint,
            ],
        ]);

        return response()->json(['ok' => true]);
    }

    private function assertEmployee(User $employee): void
    {
        abort_unless($employee->role === Role::Employee, 404);
    }

    /**
     * Hanya boleh mendaftar bila sesi memang dikunci untuk karyawan tsb.
     */
    private function assertTarget(Request $request, User $employee): void
    {
        $this->assertEmployee($employee);

        abort_unless(
            $request->session()->get(self::SESSION_KEY) === $employee->getKey(),
            409,
            'Sesi pendaftaran tidak (lagi) dikunci untuk karyawan ini.',
        );
    }
}
