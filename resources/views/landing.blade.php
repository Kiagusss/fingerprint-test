@extends('layouts.app')

@section('title', 'Beranda Diagnostik')

@section('content')
@php
    $badge = fn (bool $ok) => $ok ? 'badge-pass' : 'badge-fail';
    $okText = fn (bool $ok) => $ok ? 'OK' : 'MASALAH';
@endphp

<div class="mb-6">
    <h1 class="text-xl font-semibold tracking-tight">Beranda Diagnostik</h1>
    <p class="mt-1 text-sm text-ink-500">
        Status koneksi HTTPS, dukungan WebAuthn browser, sesi Laravel, identitas akun, dan pendaftaran passkey.
    </p>
</div>

<div class="grid gap-5 lg:grid-cols-2">

    {{-- Server-side environment --}}
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Status Server</h2>
            @auth
                <span class="badge-info">{{ $server['app_env'] }}</span>
            @endauth
        </div>
        <div class="card-body">
            <dl class="kv">
                <dt>HTTPS terkonfigurasi</dt>
                <dd><span class="{{ $badge($server['server_https']) }}">{{ $okText($server['server_https']) }}</span>
                    <span class="mono-val ml-2">{{ $server['app_url'] ?? '' }}</span></dd>

                <dt>Relying Party ID</dt>
                <dd class="mono-val">{{ $server['rp_id'] ?? '—' }}</dd>

                <dt>Allowed origins</dt>
                <dd class="mono-val">{{ implode(', ', $server['allowed_origins']) ?: '—' }}</dd>

                <dt>WebAuthn timeout</dt>
                <dd class="mono-val">{{ $server['webauthn_timeout_ms'] ? $server['webauthn_timeout_ms'] . ' ms' : '—' }}</dd>

                @auth
                    <dt>PHP / Laravel</dt>
                    <dd class="mono-val">{{ $server['php_version'] }} / {{ $server['laravel_version'] }}</dd>

                    <dt>Debug mode</dt>
                    <dd>
                        @if($server['debug'])
                            <span class="badge-warn">AKTIF — jangan dipakai di server publik</span>
                        @else
                            <span class="badge-pass">Nonaktif</span>
                        @endif
                    </dd>
                @else
                    <p class="mt-3 text-xs text-ink-400">
                        Detail versi software, mode debug, dan konfigurasi sesi server hanya ditampilkan untuk pengguna yang sudah masuk.
                    </p>
                @endauth
            </dl>
        </div>
    </section>

    {{-- Browser-side capabilities (filled by JS) --}}
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Dukungan Browser</h2>
            <span class="badge-info" data-browser-origin>—</span>
        </div>
        <div class="card-body">
            <dl class="kv" data-browser-caps>
                <dt>Memeriksa…</dt>
                <dd class="text-ink-400">Menjalankan pemeriksaan WebAuthn di browser.</dd>
            </dl>
            <p class="mt-3 text-xs text-ink-400">
                Pemeriksaan ini dijalankan di sisi klien. Server tidak pernah menerima data biometrik, PIN, atau private key.
            </p>
        </div>
    </section>

    {{-- Session / identity --}}
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Sesi Laravel &amp; Identitas Akun</h2>
            @auth
                <span class="badge-pass">Terautentikasi</span>
            @else
                <span class="badge-untested">Tamu</span>
            @endauth
        </div>
        <div class="card-body">
            @auth
                <dl class="kv">
                    <dt>Akun aplikasi</dt>
                    <dd><span class="font-medium">{{ $user->username }}</span> <span class="badge-info ml-1">{{ $user->role->label() }}</span></dd>

                    <dt>Nama tampilan</dt>
                    <dd>{{ $user->name }}</dd>

                    <dt>Autentikasi terakhir</dt>
                    <dd>{{ $user->last_authenticated_at?->diffForHumans() ?? '—' }}
                        <span class="mono-val ml-2">{{ $user->last_authenticated_at?->format('Y-m-d H:i:s') ?? '' }}</span></dd>

                    <dt>Metode autentikasi terakhir</dt>
                    <dd class="mono-val">{{ $user->last_auth_method ?? '—' }}</dd>

                    <dt>Passkey terdaftar</dt>
                    <dd>{{ $passkeys->count() }} kredensial</dd>

                    @if($osAccountLabel)
                        <dt>Label akun Windows (manual)</dt>
                        <dd><span class="badge-warn">{{ $osAccountLabel }}</span>
                            <span class="ml-1 text-xs text-ink-400">label yang Anda isi sendiri — bukan bukti identitas Windows</span></dd>
                    @endif
                </dl>
                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('dashboard') }}" class="btn-primary btn-sm">Ke Dashboard</a>
                    <a href="{{ route('passkeys.index') }}" class="btn-secondary btn-sm">Kelola Passkey</a>
                </div>
            @else
                <p class="text-sm text-ink-500">Belum ada sesi terautentikasi. Masuk dengan password (setup awal) atau passkey bila sudah terdaftar.</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('login') }}" class="btn-primary btn-sm">Masuk</a>
                </div>
            @endauth
        </div>
    </section>

    {{-- Session cookie policy --}}
    @auth
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Kebijakan Session Cookie</h2>
        </div>
        <div class="card-body">
            <dl class="kv">
                <dt>Driver</dt>
                <dd class="mono-val">{{ $server['session_driver'] }}</dd>
                <dt>Secure</dt>
                <dd><span class="{{ $badge($server['session_secure_cookie']) }}">{{ $okText($server['session_secure_cookie']) }}</span></dd>
                <dt>HttpOnly</dt>
                <dd><span class="{{ $badge($server['session_http_only']) }}">{{ $okText($server['session_http_only']) }}</span></dd>
                <dt>SameSite</dt>
                <dd class="mono-val">{{ $server['session_same_site'] ?? '—' }}</dd>
                <dt>Masa hidup</dt>
                <dd class="mono-val">{{ $server['session_lifetime_minutes'] }} menit</dd>
            </dl>
            <p class="mt-3 text-xs text-ink-400">
                Sesi disimpan per cookie browser. Dua profil Chrome (Windows A dan Windows B) memiliki cookie dan session store terpisah.
            </p>
        </div>
    </section>
    @endauth
</div>

{{-- Mode explanation --}}
<section class="card mt-5">
    <div class="card-header">
        <h2 class="card-title">Mode Identitas Windows: mana yang terbukti?</h2>
    </div>
    <div class="card-body space-y-4">
        <div class="rounded-md border border-emerald-200 bg-emerald-50/50 p-4">
            <div class="flex items-center gap-2">
                <span class="badge-pass">Mode A — didukung penuh</span>
                <span class="text-sm font-medium">Passkey &amp; sesi per profil Windows</span>
            </div>
            <p class="mt-2 text-sm text-ink-600">
                Login/passkey dilakukan di tiap profil Windows. Chrome mempertahankan sesi masing-masing selama cookie dan session store terpisah.
                Identitas yang diketahui server adalah akun aplikasi pemilik passkey — <strong>bukan</strong> nama akun Windows.
            </p>
        </div>
        <div class="rounded-md border border-amber-200 bg-amber-50/50 p-4">
            <div class="flex items-center gap-2">
                <span class="badge-warn">Mode B — tidak diklaim</span>
                <span class="text-sm font-medium">Identitas Windows terintegrasi otomatis</span>
            </div>
            <p class="mt-2 text-sm text-ink-600">
                WebAuthn/Windows Hello tidak memberi tahu server nama akun Windows yang aktif, dan tidak ada cara kriptografis untuk mengetahuinya
                hanya dari sebuah HTTP request. Mode ini hanya layak bila infra mendukung Windows Integrated Authentication / Entra ID.
                Detail dan keterbatasannya ada di halaman <a href="{{ route('documentation') }}" class="underline">Dokumentasi</a>.
            </p>
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script type="module">
    const { detectSupport } = window.helloTestPasskeys;

    const list = document.querySelector('[data-browser-caps]');
    const originEl = document.querySelector('[data-browser-origin]');

    const row = (label, value, tone = 'info') => {
        const cls = { pass: 'badge-pass', fail: 'badge-fail', warn: 'badge-warn', info: 'badge-info' }[tone];
        return `<dt>${label}</dt><dd><span class="${cls}">${value}</span></dd>`;
    };

    detectSupport().then((caps) => {
        originEl.textContent = caps.origin;
        list.innerHTML = [
            row('Secure context (HTTPS)', caps.secureContext ? 'YA' : 'TIDAK', caps.secureContext ? 'pass' : 'fail'),
            row('API WebAuthn tersedia', caps.webauthnApi ? 'YA' : 'TIDAK', caps.webauthnApi ? 'pass' : 'fail'),
            row('Platform authenticator (Windows Hello)', caps.platformAuthenticator ? 'TERSEDIA' : 'TIDAK TERDETEKSI', caps.platformAuthenticator ? 'pass' : 'warn'),
            row('Conditional mediation (autofill)', caps.conditionalMediation ? 'TERSEDIA' : '—', caps.conditionalMediation ? 'pass' : 'info'),
        ].join('');
    }).catch((e) => {
        list.innerHTML = row('Pemeriksaan gagal', String(e?.message ?? e), 'fail');
    });
</script>
@endpush
