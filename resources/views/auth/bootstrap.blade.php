@extends('layouts.app')

@section('title', 'Bootstrap Admin')

@section('content')
<div class="mx-auto max-w-2xl">
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Registrasi admin pertama</h2>
            <span class="badge-warn">sekali jalan</span>
        </div>
        <div class="card-body">
            <p class="text-sm text-ink-600">
                Aplikasi ini belum memiliki akun sama sekali. Daftarkan admin pertama dengan Windows Hello —
                tanpa password. Halaman ini menutup diri sendiri begitu admin pertama berhasil didaftarkan.
            </p>

            <form id="form-bootstrap" class="mt-4 space-y-4">
                <div>
                    <label class="label" for="bootstrap-username">Username admin</label>
                    <input class="input" id="bootstrap-username" type="text" maxlength="64" required
                           placeholder="admin" autocomplete="off">
                </div>
                <div>
                    <label class="label" for="bootstrap-name">Nama tampil</label>
                    <input class="input" id="bootstrap-name" type="text" maxlength="120" required
                           placeholder="Admin IT" autocomplete="off">
                </div>
                <button type="submit" id="btn-bootstrap" class="btn-primary w-full">
                    Daftarkan admin dengan Windows Hello
                </button>
            </form>

            <div id="bootstrap-msg" class="mt-4 hidden rounded-md border px-3 py-2 text-sm"></div>

            <div class="mt-6 space-y-2 rounded-md border border-ink-200 bg-ink-50 p-3 text-xs text-ink-500">
                <p><strong class="text-ink-600">Prasyarat:</strong> origin harus https (secure context) agar Windows Hello dapat berjalan.</p>
                <p><strong class="text-ink-600">Setelah ini:</strong> admin mendaftarkan Windows Hello setiap karyawan dari halaman Karyawan. Tidak ada password yang dibuat pada langkah mana pun.</p>
                <p><strong class="text-ink-600">Keamanan:</strong> bila aplikasi tidak berada di environment <code class="mono-val">local</code>, rute ini mensyaratkan <code class="mono-val">?key=</code> yang cocok dengan <code class="mono-val">BOOTSTRAP_KEY</code>.</p>
            </div>
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script type="module">
    const form = document.getElementById('form-bootstrap');
    const btn = document.getElementById('btn-bootstrap');
    const msg = document.getElementById('bootstrap-msg');

    const show = (text, ok) => {
        msg.className = 'mt-4 rounded-md border px-3 py-2 text-sm ' +
            (ok ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800');
        msg.textContent = text;
    };

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    const post = async (url, body) => {
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body ?? {}),
        });

        let payload = null;
        try { payload = await res.json(); } catch { payload = null; }

        if (!res.ok) {
            const message = payload?.message
                ?? (payload?.errors ? Object.values(payload.errors).flat()[0] : null)
                ?? `Permintaan gagal (HTTP ${res.status}).`;
            const err = new Error(message);
            err.status = res.status;
            throw err;
        }

        return payload;
    };

    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        btn.disabled = true;

        const username = document.getElementById('bootstrap-username').value.trim();
        const name = document.getElementById('bootstrap-name').value.trim();

        try {
            const { options } = await post('/bootstrap/options', { username, name });

            const { bootstrapPublicKey } = window.helloTestPasskeys;
            const credential = await bootstrapPublicKey(options);

            await post('/bootstrap/passkey', { name: `Windows Hello — ${username}`, credential });

            show('Admin terdaftar. Mengalihkan ke halaman masuk…', true);
            setTimeout(() => window.location.assign('/login'), 900);
        } catch (e) {
            show(e?.message ?? 'Bootstrap gagal.', false);
            btn.disabled = false;
        }
    });
</script>
@endpush
