@extends('layouts.app')

@section('title', 'Kelola Windows Hello')

@section('content')
<div class="mb-6">
    <h1 class="text-xl font-semibold tracking-tight">Kelola Windows Hello</h1>
    <p class="mt-1 text-sm text-ink-500">
        Daftar kredensial Windows Hello milik <span class="font-medium">{{ $user->username }}</span>.
        Menambah atau mencabut kredensial memerlukan konfirmasi ulang Windows Hello melalui middleware <code class="mono-val">passkey.confirm</code>.
    </p>
</div>

<div class="grid gap-5 lg:grid-cols-3">
    <section class="card lg:col-span-2">
        <div class="card-header">
            <h2 class="card-title">Windows Hello Terdaftar</h2>
            <span class="badge-info">{{ $passkeys->count() }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Authenticator</th>
                        <th>Credential ID (fp)</th>
                        <th>Dibuat</th>
                        <th>Terakhir dipakai</th>
                        <th class="w-24"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($passkeys as $passkey)
                        <tr data-passkey-row="{{ $passkey->id }}">
                            <td class="font-medium">{{ $passkey->name }}</td>
                            <td>{{ $passkey->authenticator ?? '—' }}</td>
                            <td class="mono-val" title="Fingerprint SHA-256 (16 karakter pertama)">
                                {{ 'sha256:' . substr(hash('sha256', $passkey->credential_id), 0, 16) }}
                            </td>
                            <td class="text-ink-500">{{ $passkey->created_at->format('Y-m-d H:i') }}</td>
                            <td class="text-ink-500">{{ $passkey->last_used_at?->format('Y-m-d H:i') ?? 'belum' }}</td>
                            <td>
                                <button type="button" class="btn-danger btn-sm" data-delete="{{ $passkey->id }}">Cabut</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-ink-400">Belum ada kredensial Windows Hello untuk akun ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-body border-t border-ink-200 text-xs text-ink-400">
            Fingerprint tidak dapat dibalik menjadi credential. Private key dan data biometrik tidak pernah dikirim ke server.
        </div>
    </section>

    <section class="card">
        <div class="card-header"><h2 class="card-title">Tambah Windows Hello</h2></div>
        <div class="card-body">
            <p class="mb-4 text-sm text-ink-500">
                Daftarkan kredensial Windows Hello baru untuk akun ini. Nama membantu membedakan perangkat/akun Windows.
            </p>

            <form id="form-add-passkey" class="space-y-4">
                <div>
                    <label class="label" for="passkey-name">Nama kredensial</label>
                    <input class="input" id="passkey-name" name="name" type="text" maxlength="255"
                           placeholder="Windows A - test-admin" required>
                </div>
                <button type="submit" id="btn-add-passkey" class="btn-primary w-full">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    Daftarkan dengan Windows Hello
                </button>
            </form>

            <div id="passkey-msg" class="mt-3 hidden rounded-md border px-3 py-2 text-sm"></div>

            <div class="mt-5 space-y-2 rounded-md border border-ink-200 bg-ink-50 p-3 text-xs text-ink-500">
                <p><strong class="text-ink-600">Konfirmasi:</strong> bila diminta, verifikasi ulang dengan Windows Hello terlebih dahulu (menggantikan prompt password).</p>
                <p><strong class="text-ink-600">Exclude credentials:</strong> authenticator yang sama tidak dapat didaftarkan dua kali.</p>
                <p><strong class="text-ink-600">Resident key</strong> dan <strong class="text-ink-600">user verification</strong> diwajibkan agar kredensial dapat dipakai tanpa mengetik username.</p>
            </div>
        </div>
    </section>
</div>

<section class="card mt-5">
    <div class="card-body">
        <div class="text-sm text-ink-600">
            <strong>Session fingerprint akun ini:</strong> <span class="mono-val">{{ $sessionFingerprint ?? '—' }}</span>
        </div>
        <p class="mt-1 text-xs text-ink-400">Fingerprint ini di-hash dengan app key dan hanya berguna untuk membedakan sesi di dalam log.</p>
    </div>
</section>
@endsection

@push('scripts')
<script type="module">
    const { registerPasskey, deletePasskey } = window.helloTestPasskeys;
    const msg = document.getElementById('passkey-msg');
    const btn = document.getElementById('btn-add-passkey');

    const show = (text, ok) => {
        msg.className = 'mt-3 rounded-md border px-3 py-2 text-sm ' +
            (ok ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800');
        msg.textContent = text;
    };

    document.getElementById('form-add-passkey').addEventListener('submit', async (ev) => {
        ev.preventDefault();

        const name = document.getElementById('passkey-name').value.trim() || 'Windows Hello';
        btn.disabled = true;

        // passkey.confirm returns 423 until the user re-verifies. Confirm with
        // Windows Hello, then retry the action exactly once.
        const withConfirmation = async (action) => {
            try {
                return await action();
            } catch (e) {
                if (e?.status !== 423) throw e;
                show('Perlu konfirmasi ulang — verifikasi Windows Hello…', true);
                await window.helloTestPasskeys.confirmWithPasskey();
                return action();
            }
        };

        try {
            await withConfirmation(() => registerPasskey(name));
            show('Windows Hello berhasil didaftarkan. Memuat ulang…', true);
            setTimeout(() => window.location.reload(), 900);
        } catch (e) {
            show(e?.message ?? 'Pendaftaran Windows Hello gagal.', false);
            btn.disabled = false;
        }
    });

    document.querySelectorAll('[data-delete]').forEach((button) => {
        button.addEventListener('click', async () => {
            const id = button.getAttribute('data-delete');
            if (!window.confirm('Cabut kredensial Windows Hello ini? Tindakan ini tidak dapat dibatalkan.')) return;

            button.disabled = true;
            try {
                let result;
                try {
                    result = await deletePasskey(id);
                } catch (e) {
                    if (e?.status !== 423) throw e;
                    show('Perlu konfirmasi ulang — verifikasi Windows Hello…', true);
                    await window.helloTestPasskeys.confirmWithPasskey();
                    result = await deletePasskey(id);
                }
                show('Windows Hello dicabut. Memuat ulang…', true);
                setTimeout(() => window.location.reload(), 900);
            } catch (e) {
                show(e?.message ?? 'Pencabutan gagal.', false);
                button.disabled = false;
            }
        });
    });
</script>
@endpush
