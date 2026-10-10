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
                'title' => 'Pendaftaran passkey di Windows A (test-admin)',
                'source' => 'manual',
                'expected' => 'Windows Hello prompt muncul, passkey tersimpan dan tampil di halaman Pengelolaan Passkey milik test-admin.',
                'automated_test' => null,
            ],
            [
                'key' => 'passkey_registration_win_b',
                'category' => 'webauthn',
                'title' => 'Pendaftaran passkey di Windows B (test-supervisor)',
                'source' => 'manual',
                'expected' => 'Windows Hello prompt muncul, passkey tersimpan untuk test-supervisor, terpisah dari passkey test-admin.',
                'automated_test' => null,
            ],
            [
                'key' => 'passkey_login_win_a',
                'category' => 'webauthn',
                'title' => 'Login passkey di Windows A',
                'source' => 'manual',
                'expected' => 'Setelah logout, tombol "Masuk dengan passkey" mengembalikan ke dashboard sebagai test-admin tanpa mengetik password.',
                'automated_test' => null,
            ],
            [
                'key' => 'passkey_login_win_b',
                'category' => 'webauthn',
                'title' => 'Login passkey di Windows B',
                'source' => 'manual',
                'expected' => 'Login passkey di profil Windows B masuk sebagai test-supervisor, bukan test-admin.',
                'automated_test' => null,
            ],
            [
                'key' => 'passkey_confirm_reauth',
                'category' => 'webauthn',
                'title' => 'Verifikasi ulang (confirmation) sebelum kelola passkey',
                'source' => 'manual',
                'expected' => 'Menambah/mencabut passkey meminta konfirmasi Windows Hello terlebih dahulu (middleware passkey.confirm).',
                'automated_test' => null,
            ],
            [
                'key' => 'passkey_revoked_blocks_login',
                'category' => 'webauthn',
                'title' => 'Passkey dicabut tidak bisa dipakai login',
                'source' => 'manual',
                'expected' => 'Setelah passkey dicabut dari akun, mencoba login dengan credential itu gagal.',
                'automated_test' => null,
            ],
            [
                'key' => 'passkey_cannot_cross_account',
                'category' => 'webauthn',
                'title' => 'Passkey akun lain tidak bisa dipakai menembus akun pemiliknya',
                'source' => 'auto',
                'expected' => 'Passkey milik satu user tidak pernah ter-resolve ke user lain; relasi user_id di server mengikat credential ke pemiliknya.',
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
                'title' => 'Pendaftaran passkey tanpa otorisasi ditolak',
                'source' => 'auto',
                'expected' => 'Endpoint registrasi passkey menolak tamu dan memerlukan konfirmasi password.',
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
                'expected' => 'Login username/password berhasil untuk setup awal/pemulihan, session id di-regenerate, dan tidak melemahkan kebijakan passkey.',
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
        ];
    }
}
