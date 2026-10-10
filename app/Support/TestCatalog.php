<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical catalogue of every test case shown on the "Hasil Pengujian" page.
 *
 * `source = auto`   → a PHPUnit test proves it; the runner updates its status.
 * `source = manual` → a human must execute it on real Windows profiles; it stays
 *                     `not_tested` until somebody records an outcome.
 *
 * Nothing here may ever be flipped to PASS without an actual execution.
 */
class TestCatalog
{
    /**
     * @return list<array{key:string, category:string, title:string, source:string, expected:string, automated_test:?string}>
     */
    public static function cases(): array
    {
        return [
            // ---- WebAuthn / passkey --------------------------------
            [
                'key' => 'passkey_registration_win_a',
                'category' => 'webauthn',
                'title' => 'Pendaftaran Windows Hello di Windows A (test-admin)',
                'source' => 'manual',
                'expected' => 'Prompt Windows Hello muncul, kredensial tersimpan dan tampil di halaman Windows Hello milik test-admin.',
                'automated_test' => null,
            ],
            [
                'key' => 'passkey_registration_win_b',
                'category' => 'webauthn',
                'title' => 'Pendaftaran Windows Hello di Windows B (test-supervisor)',
                'source' => 'manual',
                'expected' => 'Prompt Windows Hello muncul, kredensial tersimpan untuk test-supervisor, terpisah dari kredensial test-admin.',
                'automated_test' => null,
            ],
            [
                'key' => 'passkey_login_win_a',
                'category' => 'webauthn',
                'title' => 'Login Windows Hello di Windows A',
                'source' => 'manual',
                'expected' => 'Setelah logout, tombol "Masuk dengan Windows Hello" mengembalikan ke dashboard sebagai test-admin tanpa mengetik password.',
                'automated_test' => null,
            ],
            [
                'key' => 'passkey_login_win_b',
                'category' => 'webauthn',
                'title' => 'Login Windows Hello di Windows B',
                'source' => 'manual',
                'expected' => 'Login Windows Hello di profil Windows B masuk sebagai test-supervisor, bukan test-admin.',
                'automated_test' => null,
            ],
            [
                'key' => 'passkey_confirm_reauth',
                'category' => 'webauthn',
                'title' => 'Verifikasi ulang (confirmation) sebelum kelola kredensial',
                'source' => 'manual',
                'expected' => 'Menambah/mencabut kredensial meminta konfirmasi Windows Hello terlebih dahulu (middleware passkey.confirm).',
                'automated_test' => null,
            ],
            [
                'key' => 'passkey_revoked_blocks_login',
                'category' => 'webauthn',
                'title' => 'Kredensial dicabut tidak bisa dipakai login',
                'source' => 'manual',
                'expected' => 'Setelah kredensial dicabut dari akun, mencoba login dengan credential itu gagal.',
                'automated_test' => null,
            ],
            [
                'key' => 'passkey_cannot_cross_account',
                'category' => 'webauthn',
                'title' => 'Kredensial akun lain tidak bisa dipakai menembus akun pemiliknya',
                'source' => 'auto',
                'expected' => 'Kredensial milik satu user tidak pernah ter-resolve ke user lain; relasi user_id di server mengikat credential ke pemiliknya.',
                'automated_test' => 'Tests\\Feature\\PasskeyBindingTest::test_passkey_is_bound_to_its_owner',
            ],
            [
                'key' => 'challenge_replay',
                'category' => 'webauthn',
                'title' => 'Challenge kedaluwarsa dan replay',
                'source' => 'auto',
                'expected' => 'Options verifikasi diambil sekali dari session; submit kedua dengan options yang sama ditolak.',
                'automated_test' => 'Tests\\Feature\\WebAuthnCeremonyTest::test_verification_options_cannot_be_replayed',
            ],
            [
                'key' => 'challenge_missing',
                'category' => 'webauthn',
                'title' => 'Submit credential tanpa challenge di session',
                'source' => 'auto',
                'expected' => 'Server menolak dengan pesan sesi kedaluwarsa dan tidak memproses credential.',
                'automated_test' => 'Tests\\Feature\\WebAuthnCeremonyTest::test_login_without_session_challenge_is_rejected',
            ],
            [
                'key' => 'origin_invalid',
                'category' => 'webauthn',
                'title' => 'Origin tidak valid ditolak',
                'source' => 'auto',
                'expected' => 'Origin di luar allowed_origins tidak diterima oleh validator WebAuthn.',
                'automated_test' => 'Tests\\Feature\\WebAuthnCeremonyTest::test_invalid_origin_is_rejected',
            ],
            [
                'key' => 'rp_id_mismatch',
                'category' => 'webauthn',
                'title' => 'RP ID tidak cocok ditolak',
                'source' => 'auto',
                'expected' => 'Credential yang diterbitkan untuk RP ID lain gagal diverifikasi.',
                'automated_test' => 'Tests\\Feature\\WebAuthnCeremonyTest::test_rp_id_mismatch_is_rejected',
            ],

            // ---- Session isolation ---------------------------------
            [
                'key' => 'session_isolation_two_profiles',
                'category' => 'session',
                'title' => 'Isolasi sesi antarprofil Windows',
                'source' => 'manual',
                'expected' => 'Windows A tetap test-admin dan Windows B tetap test-supervisor setelah keduanya login; cookie/session store terpisah per profil Chrome.',
                'automated_test' => null,
            ],
            [
                'key' => 'session_persists_across_restart',
                'category' => 'session',
                'title' => 'Sesi bertahan saat browser di-restart',
                'source' => 'manual',
                'expected' => 'Menutup dan membuka ulang Chrome masih membawa sesi Laravel yang sama (buat session store terpisah), tidak perlu login ulang selama sesi valid.',
                'automated_test' => null,
            ],
            [
                'key' => 'account_switch_no_overwrite',
                'category' => 'session',
                'title' => 'Pergantian akun Windows tidak menimpa sesi Laravel',
                'source' => 'manual',
                'expected' => 'Berpindah Windows A→B→A tidak mengubah identitas sesi di sisi server; sesi Laravel terikat ke cookie browser, bukan ke akun Windows.',
                'automated_test' => null,
            ],
            [
                'key' => 'sessions_are_independent',
                'category' => 'session',
                'title' => 'Dua sesi guard berjalan independen',
                'source' => 'auto',
                'expected' => 'Dua sesi yang terautentikasi sebagai user berbeda tidak saling mempengaruhi identitas.',
                'automated_test' => 'Tests\\Feature\\SessionIsolationTest::test_two_sessions_keep_separate_identities',
            ],
            [
                'key' => 'logout_invalidates_session',
                'category' => 'session',
                'title' => 'Logout menginvalidasi sesi',
                'source' => 'auto',
                'expected' => 'Setelah logout, request berikutnya dengan cookie lama tidak lagi terautentikasi.',
                'automated_test' => 'Tests\\Feature\\SessionIsolationTest::test_logout_invalidates_the_session',
            ],

            // ---- Authorisation -------------------------------------
            [
                'key' => 'unauth_access',
                'category' => 'authorization',
                'title' => 'Akses halaman terlindungi tanpa autentikasi',
                'source' => 'auto',
                'expected' => 'Dashboard, Admin Test dan Supervisor Test mengalihkan tamu ke halaman login.',
                'automated_test' => 'Tests\\Feature\\AuthorizationTest::test_guests_are_redirected_to_login',
            ],
            [
                'key' => 'cross_role_access',
                'category' => 'authorization',
                'title' => 'Akses lintas role ditolak',
                'source' => 'auto',
                'expected' => 'test-admin tidak bisa membuka Supervisor Test dan test-supervisor tidak bisa membuka Admin Test (HTTP 403).',
                'automated_test' => 'Tests\\Feature\\AuthorizationTest::test_cross_role_access_is_forbidden',
            ],
            [
                'key' => 'role_from_client_ignored',
                'category' => 'authorization',
                'title' => 'Role dari client diabaikan',
                'source' => 'auto',
                'expected' => 'Menyisipkan role/username lewat query string, form atau header tidak mengubah otorisasi server.',
                'automated_test' => 'Tests\\Feature\\AuthorizationTest::test_client_supplied_role_is_ignored',
            ],
            [
                'key' => 'registration_requires_auth',
                'category' => 'authorization',
                'title' => 'Pendaftaran kredensial tanpa otorisasi ditolak',
                'source' => 'auto',
                'expected' => 'Endpoint registrasi kredensial menolak tamu dan memerlukan konfirmasi password.',
                'automated_test' => 'Tests\\Feature\\AuthorizationTest::test_passkey_registration_requires_authentication',
            ],
            [
                'key' => 'csrf_enforced',
                'category' => 'authorization',
                'title' => 'Proteksi CSRF aktif pada endpoint POST',
                'source' => 'auto',
                'expected' => 'POST tanpa token CSRF yang valid ditolak (HTTP 419).',
                'automated_test' => 'Tests\\Feature\\AuthorizationTest::test_post_without_csrf_token_is_rejected',
            ],
            [
                'key' => 'rate_limit_login',
                'category' => 'authorization',
                'title' => 'Rate limiting login password',
                'source' => 'auto',
                'expected' => 'Percobaan login gagal berulang dibatasi (HTTP 429) sebelum bisa brute force.',
                'automated_test' => 'Tests\\Feature\\AuthorizationTest::test_login_is_rate_limited',
            ],

            // ---- Recovery / fallback -------------------------------
            [
                'key' => 'password_fallback_works',
                'category' => 'recovery',
                'title' => 'Fallback login password tetap aman dan berfungsi',
                'source' => 'auto',
                'expected' => 'Login username/password berhasil untuk setup awal/pemulihan, session id di-regenerate, dan tidak melemahkan kebijakan Windows Hello.',
                'automated_test' => 'Tests\\Feature\\AuthenticationTest::test_password_login_succeeds_and_regenerates_session',
            ],
            [
                'key' => 'password_error_is_generic',
                'category' => 'recovery',
                'title' => 'Pesan error login tidak membocorkan informasi',
                'source' => 'auto',
                'expected' => 'Username tidak dikenal dan password salah memberi pesan yang sama.',
                'automated_test' => 'Tests\\Feature\\AuthenticationTest::test_login_failure_message_does_not_leak_user_existence',
            ],

            // ---- Sidik jari (identifikasi, bukan autentikasi) --------
            [
                'key' => 'biometric_requires_consent',
                'category' => 'biometric',
                'title' => 'Pendaftaran sidik jari tanpa persetujuan ditolak',
                'source' => 'auto',
                'expected' => 'Data biometrik tidak pernah disimpan tanpa consent pemiliknya; quality di luar 0-100 juga ditolak.',
                'automated_test' => 'Tests\\Feature\\BiometricEnrollmentTest::test_enrollment_requires_consent_and_a_quality_score',
            ],
            [
                'key' => 'biometric_device_signature',
                'category' => 'biometric',
                'title' => 'Agen tanpa tanda tangan yang sah ditolak',
                'source' => 'auto',
                'expected' => 'Request tanpa tanda tangan, dari perangkat tak dikenal, dengan jam melenceng, atau dengan body yang diubah, ditolak 401.',
                'automated_test' => 'Tests\\Feature\\BiometricEnrollmentTest::test_agent_endpoints_reject_a_tampered_body',
            ],
            [
                'key' => 'biometric_never_authenticates',
                'category' => 'biometric',
                'title' => 'Endpoint agen tidak membuat sesi; sesi hanya lahir dari tiket sekali pakai',
                'source' => 'auto',
                'expected' => 'Tidak ada endpoint agen (heartbeat, pendaftaran, pencocokan) yang membuat sesi login. Sesi hanya dibuat ketika browser menukar tiket 60 detik sekali pakai di /fingerprint/login/{token}, dan hanya bila template_ref menunjuk pendaftaran yang masih aktif.',
                'automated_test' => 'Tests\\Feature\\BiometricEnrollmentTest::test_the_biometric_layer_never_creates_a_session',
            ],
            [
                'key' => 'fingerprint_login_ticket_single_use',
                'category' => 'biometric',
                'title' => 'Tiket masuk dari sidik jari sekali pakai dan berumur pendek',
                'source' => 'auto',
                'expected' => 'Tiket hanya bisa ditukar sekali, kedaluwarsa setelah 60 detik, dan yang tersimpan hanya sha256 tokennya — bukan token mentahnya.',
                'automated_test' => 'Tests\\Feature\\FingerprintLoginTest::test_a_ticket_can_only_be_used_once',
            ],
            [
                'key' => 'fingerprint_login_needs_active_enrollment',
                'category' => 'biometric',
                'title' => 'Tiket masuk hanya untuk jari yang pendaftarannya masih aktif',
                'source' => 'auto',
                'expected' => 'Permintaan tiket dengan template_ref yang tidak dikenal, atau yang pendaftarannya sudah dicabut, dijawab 422 dan tidak menghasilkan tiket.',
                'automated_test' => 'Tests\\Feature\\FingerprintLoginTest::test_a_ticket_cannot_be_issued_for_an_unknown_or_revoked_enrollment',
            ],
            [
                'key' => 'biometric_template_encrypted',
                'category' => 'biometric',
                'title' => 'Template sidik jari terenkripsi saat disimpan',
                'source' => 'auto',
                'expected' => 'Kolom template berisi ciphertext, bukan template mentah, dan tidak pernah muncul di log diagnostik.',
                'automated_test' => 'Tests\\Feature\\BiometricEnrollmentTest::test_a_stored_template_is_encrypted_at_rest',
            ],
            [
                'key' => 'biometric_admin_only',
                'category' => 'biometric',
                'title' => 'Halaman perangkat sidik jari hanya untuk admin',
                'source' => 'auto',
                'expected' => 'Tamu dialihkan ke login dan akun non-admin menerima 403.',
                'automated_test' => 'Tests\\Feature\\BiometricEnrollmentTest::test_only_admins_reach_the_management_page',
            ],
            [
                'key' => 'biometric_revoke_stops_agent',
                'category' => 'biometric',
                'title' => 'Pendaftaran yang dicabut berhenti dikirim ke agen',
                'source' => 'auto',
                'expected' => 'Setelah admin mencabut satu pendaftaran, agen tidak lagi menerimanya saat sinkronisasi.',
                'automated_test' => 'Tests\\Feature\\BiometricEnrollmentTest::test_an_admin_can_revoke_an_enrollment_and_the_agent_stops_receiving_it',
            ],
            [
                'key' => 'biometric_identify_shared_pc',
                'category' => 'biometric',
                'title' => 'Identifikasi sidik jari di PC bersama (perangkat nyata)',
                'source' => 'manual',
                'expected' => 'Karyawan menempelkan jari di agen pada PC bersama, layar login terisi username yang benar tanpa mengetik, lalu password/passkey miliknya tetap diminta. Prasyarat: SDK pemindai mendukung identifikasi 1:N (lihat docs/BIOMETRIC.md bagian 5).',
                'automated_test' => null,
            ],
        ];
    }
}
