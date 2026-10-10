@extends('layouts.app')

@section('title', 'Dokumentasi Konfigurasi')

@section('content')
<div class="mb-6">
    <h1 class="text-xl font-semibold tracking-tight">Dokumentasi Konfigurasi</h1>
    <p class="mt-1 text-sm text-ink-500">
        Ringkasan lingkungan, dua mode identitas Windows, prosedur instalasi/HTTPS, pengujian, dan rollback.
        Dokumentasi lengkap ada di <code class="mono-val">README.md</code> pada root proyek.
    </p>
</div>

@include('partials.docs-nav')

<section class="card mb-5" id="lingkungan">
    <div class="card-header"><h2 class="card-title">1. Lingkungan Saat Ini (snapshot server)</h2></div>
    <div class="card-body">
        <dl class="kv">
            <dt>APP_URL</dt><dd class="mono-val">{{ $server['app_url'] }}</dd>
            <dt>APP_ENV</dt><dd class="mono-val">{{ $server['app_env'] }}</dd>
            <dt>PHP</dt><dd class="mono-val">{{ $server['php_version'] }}</dd>
            <dt>Laravel</dt><dd class="mono-val">{{ $server['laravel_version'] }}</dd>
            <dt>Relying Party ID</dt><dd class="mono-val">{{ $server['rp_id'] ?? '—' }}</dd>
            <dt>Allowed origins</dt><dd class="mono-val">{{ implode(', ', $server['allowed_origins']) ?: '—' }}</dd>
            <dt>WebAuthn timeout</dt><dd class="mono-val">{{ $server['webauthn_timeout_ms'] ?? '—' }} ms</dd>
            <dt>Session driver</dt><dd class="mono-val">{{ $server['session_driver'] }} · {{ $server['session_lifetime_minutes'] }} menit</dd>
            <dt>Session cookie</dt>
            <dd class="mono-val">
                secure={{ $server['session_secure_cookie'] ? 'true' : 'false' }},
                httponly={{ $server['session_http_only'] ? 'true' : 'false' }},
                samesite={{ $server['session_same_site'] ?? '—' }}
            </dd>
            <dt>Debug</dt>
            <dd>{!! $server['debug'] ? '<span class="badge-warn">AKTIF</span>' : '<span class="badge-pass">Nonaktif</span>' !!}</dd>
        </dl>
    </div>
</section>

<section class="card mb-5" id="mode">
    <div class="card-header"><h2 class="card-title">2. Dua Mode Identitas Windows</h2></div>
    <div class="card-body space-y-4">
        <div>
            <h3 class="text-sm font-semibold">Mode A — Passkey &amp; sesi per profil Windows (didukung)</h3>
            <p class="mt-1 text-sm text-ink-600">
                Pengguna login/passkey di tiap profil Windows. Chrome mempertahankan sesi masing-masing selama cookie dan session store terpisah.
                Server mengetahui <em>akun aplikasi</em> pemilik passkey, bukan nama akun Windows.
            </p>
            <ul class="mt-2 list-inside list-disc text-sm text-ink-600">
                <li>Identitas = akun aplikasi (username + role di basis data).</li>
                <li>Isolasi = cookie/session store per profil browser.</li>
                <li>Tidak ada klaim tentang identitas Windows aktif.</li>
            </ul>
        </div>
        <div>
            <h3 class="text-sm font-semibold">Mode B — Identitas Windows terintegrasi (dievaluasi, tidak diklaim)</h3>
            <p class="mt-1 text-sm text-ink-600">
                Mengetahui akun Windows aktif secara otomatis memerlukan Windows Integrated Authentication / Negotiate (Kerberos/NTLM) dengan
                Active Directory, atau Microsoft Entra ID. Itu bukan fitur WebAuthn dan tidak dapat disimpulkan dari sebuah HTTP request biasa.
            </p>
            <ul class="mt-2 list-inside list-disc text-sm text-ink-600">
                <li>Mode A <strong>tidak</strong> dapat mengetahui identitas Windows aktif secara kriptografis dari HTTP request.</li>
                <li>Jika infrastruktur tidak mendukung IWA/AD/Entra ID, Mode B tidak diimplementasikan (tidak ada implementasi palsu).</li>
                <li>Aplikasi pendamping Windows, bila diperlukan, harus jadi komponen terpisah dengan autentikasi kuat — bukan endpoint lokal yang mempercayai nama akun dari query/header.</li>
            </ul>
        </div>
    </div>
</section>

<section class="card mb-5" id="https">
    <div class="card-header"><h2 class="card-title">3. HTTPS &amp; RP ID</h2></div>
    <div class="card-body space-y-3 text-sm text-ink-600">
        <p>WebAuthn hanya berjalan pada <em>secure context</em> (HTTPS atau <code class="mono-val">localhost</code>). Karena pengujian memakai dua profil Windows di satu host name, digunakan HTTPS lokal.</p>
        <p><strong>Lokal:</strong> mkcert membuat CA lokal + sertifikat untuk <code class="mono-val">hello-test.local</code>, lalu Caddy men-terminasi TLS dan meneruskan ke PHP.</p>
        <p><strong>Staging:</strong> gunakan sertifikat nyata untuk subdomain uji (mis. <code class="mono-val">hello-test.example.com</code>) dan set <code class="mono-val">APP_URL</code> ke origin itu.</p>
        <div class="rounded-md border border-ink-200 bg-ink-50 p-3">
            <div class="font-medium text-ink-700">Aturan penting</div>
            <ul class="mt-1 list-inside list-disc space-y-0.5">
                <li>RP ID = hostname (tanpa skema/port), mis. <code class="mono-val">hello-test.local</code>.</li>
                <li>Origin yang diizinkan harus tepat sama dengan yang dilaporkan browser.</li>
                <li>Passkey terikat pada RP ID. Berganti hostname = passkey lama tidak valid.</li>
            </ul>
        </div>
    </div>
</section>

<section class="card mb-5" id="akun">
    <div class="card-header"><h2 class="card-title">4. Akun Pengujian</h2></div>
    <div class="card-body text-sm text-ink-600">
        <table class="table">
            <thead><tr><th>Username</th><th>Role</th><th>Hak akses</th></tr></thead>
            <tbody>
                <tr><td class="mono-val">test-admin</td><td><span class="badge-info">Admin</span></td><td>Membuka Admin Test</td></tr>
                <tr><td class="mono-val">test-supervisor</td><td><span class="badge-info">Supervisor</span></td><td>Membuka Supervisor Test</td></tr>
            </tbody>
        </table>
        <p class="mt-3">
            Password tidak di-hardcode: dibaca dari <code class="mono-val">TEST_ADMIN_PASSWORD</code> / <code class="mono-val">TEST_SUPERVISOR_PASSWORD</code>.
            Bila kosong di lingkungan lokal, seeder membuat password acak dan menampilkannya sekali. Di lingkungan non-lokal seeder menolak berjalan.
        </p>
        <p class="mt-2"><strong>Ganti kredensial:</strong> ubah nilai env lalu jalankan <code class="mono-val">php artisan db:seed --class=Database\\Seeders\\RolesAndUsersSeeder</code>.</p>
    </div>
</section>

<section class="card mb-5" id="uji">
    <div class="card-header"><h2 class="card-title">5. Prosedur Pengujian Dua Akun Windows</h2></div>
    <div class="card-body">
        @include('partials.test-procedure')
    </div>
</section>

<section class="card mb-5" id="keamanan">
    <div class="card-header"><h2 class="card-title">6. Kontrol Keamanan</h2></div>
    <div class="card-body">
        <ul class="list-inside list-disc space-y-1.5 text-sm text-ink-600">
            <li>Challenge acak 32 byte dibuat server, disimpan di session, dan diambil sekali (<em>pull</em>) → anti replay.</li>
            <li>Origin, RP ID, credential ID, dan signature diverifikasi di server oleh <code class="mono-val">web-auth/webauthn-lib</code>.</li>
            <li>User verification diwajibkan (Windows Hello biometrik/PIN).</li>
            <li>Session ID di-regenerate setelah login dan di-invalidate saat logout.</li>
            <li>Role ditegakkan server-side dari basis data; input role dari klien diabaikan.</li>
            <li>Rate limiting pada login password (<code class="mono-val">throttle:6,1</code>) dan endpoint passkey.</li>
            <li>CSRF aktif pada endpoint POST/PATCH/DELETE.</li>
            <li>Pendaftaran/pencabutan passkey memerlukan konfirmasi ulang (<code class="mono-val">password.confirm</code>).</li>
            <li>Log diagnostik tidak menyimpan private key, biometric, cookie, atau session id mentah (hanya fingerprint).</li>
        </ul>
    </div>
</section>

<section class="card" id="rollback">
    <div class="card-header"><h2 class="card-title">7. Rollback &amp; Penghapusan</h2></div>
    <div class="card-body space-y-3 text-sm text-ink-600">
        <p>PoC ini terisolasi. Rollback tidak menyentuh dashboard produksi:</p>
        <pre class="overflow-x-auto rounded-md border border-ink-200 bg-ink-50 p-3 text-xs"># hentikan &amp; hapus database uji (volume ikut terhapus)
docker compose down -v

# hentikan aplikasi lokal
# (hentikan proses: php artisan serve / caddy)

# hapus seluruh proyek
rm -rf ~/Documents/hello-test

# opsional: hapus CA lokal mkcert (hati-hati bila dipakai proyek lain)
mkcert -uninstall</pre>
        <p class="text-xs text-ink-400">Jangan menjalankan migration destruktif terhadap basis data produksi. Semua migrasi di sini hanya untuk basis data <code class="mono-val">hello_test</code>.</p>
    </div>
</section>
@endsection
