@extends('layouts.app')

@section('title', 'Masuk')

@section('content')
<div class="mx-auto grid max-w-4xl gap-5 {{ $passwordEnabled ? 'lg:grid-cols-2' : '' }}">

    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Masuk dengan Passkey (Windows Hello)</h2>
            <span class="badge-info">utama</span>
        </div>
        <div class="card-body">
            @if($hasPasskeys)
                <p class="mb-4 text-sm text-ink-500">
                    Gunakan passkey yang sudah terdaftar. Kredensial ini tersimpan pada authenticator (Windows Hello) dan diverifikasi di server.
                </p>
                <button type="button" id="btn-passkey-login" class="btn-primary w-full disabled:opacity-50" style="display:none">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M6 21v-2a4 4 0 0 1 4-4h1"/></svg>
                    Masuk dengan Windows Hello
                </button>
                <div id="passkey-msg" class="mt-3 hidden rounded-md border px-3 py-2 text-sm"></div>
                <p id="passkey-unsupported" class="hidden text-sm text-rose-700">
                    Browser ini tidak mendukung WebAuthn atau tidak berada pada secure context. Gunakan Chrome pada <code class="mono-val">https://hello-test.local</code>.
                </p>
            @else
                @if($hasUsers)
                    <p class="text-sm text-ink-500">
                        Belum ada passkey terdaftar. Mintalah admin mendaftarkan sidik jari Anda dari halaman
                        <span class="font-medium">Karyawan</span>, atau masuk dengan username &amp; password bila jalur pemulihan diaktifkan.
                    </p>
                @else
                    <p class="text-sm text-ink-500">
                        Instalasi ini belum punya akun apa pun. Daftarkan admin pertama dengan sidik jari —
                        tanpa password.
                    </p>
                    <a href="{{ route('bootstrap.show') }}" class="btn-primary mt-3 w-full">Daftarkan admin pertama</a>
                @endif
            @endif
        </div>
    </section>

    @if($passwordEnabled)
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Masuk dengan Username &amp; Password</h2>
            <span class="badge-warn">setup &amp; pemulihan</span>
        </div>
        <div class="card-body">
            <p class="mb-4 text-sm text-ink-500">
                Digunakan untuk setup awal dan pemulihan bila passkey hilang. Kebijakan keamanan passkey tidak dilemahkan oleh jalur ini.
            </p>

            <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="label" for="username">Username</label>
                    <input class="input" id="username" name="username" type="text" autocomplete="username"
                           value="{{ old('username') }}" required autofocus>
                </div>

                <div>
                    <label class="label" for="password">Password</label>
                    <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
                </div>

                <div>
                    <label class="label" for="os_account_label">Label akun Windows (opsional, diagnostik)</label>
                    <input class="input" id="os_account_label" name="os_account_label" type="text" maxlength="64"
                           placeholder="mis. Windows A / Windows B" value="{{ old('os_account_label') }}">
                    <p class="mt-1.5 text-xs text-ink-400">
                        Hanya label yang Anda tulis sendiri untuk memudahkan pembacaan log. Server tidak menganggapnya sebagai bukti identitas Windows.
                    </p>
                </div>

                <label class="flex items-center gap-2 text-sm text-ink-600">
                    <input type="checkbox" name="remember" value="1" class="rounded border-ink-300">
                    Ingat saya di profil browser ini
                </label>

                <button type="submit" class="btn-primary w-full">Masuk</button>
            </form>
        </div>
    </section>
    @endif
</div>

<section class="card mt-5">
    <div class="card-body text-xs text-ink-500">
        <strong class="text-ink-600">Catatan pengujian:</strong>
        jalankan Windows A dan Windows B sebagai dua profil Chrome berbeda. Cookie sesi tersimpan per profil, sehingga identitas akun aplikasi
        (test-admin pada A, test-supervisor pada B) tetap terpisah. Pergantian akun Windows tidak menimpa sesi Laravel.
    </div>
</section>
@endsection

@push('scripts')
<script type="module">
    const btn = document.getElementById('btn-passkey-login');
    const msg = document.getElementById('passkey-msg');
    const unsupported = document.getElementById('passkey-unsupported');

    const show = (text, ok) => {
        msg.className = 'mt-3 rounded-md border px-3 py-2 text-sm ' +
            (ok ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800');
        msg.textContent = text;
    };

    if (btn) {
        const { detectSupport, loginWithPasskey } = window.helloTestPasskeys;

        detectSupport().then((caps) => {
            if (caps.secureContext && caps.webauthnApi) {
                btn.style.display = '';
            } else if (unsupported) {
                unsupported.classList.remove('hidden');
            }
        });

        btn.addEventListener('click', async () => {
            btn.disabled = true;
            try {
                const result = await loginWithPasskey();
                show('Berhasil. Mengalihkan…', true);
                window.location.assign(result?.redirect ?? '{{ route('dashboard') }}');
            } catch (e) {
                show(e?.message ?? 'Login passkey gagal.', false);
                btn.disabled = false;
            }
        });
    }
</script>
@endpush
