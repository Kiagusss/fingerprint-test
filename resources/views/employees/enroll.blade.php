@extends('layouts.app')

@section('title', 'Pendaftaran Sidik Jari Karyawan')

@section('content')
@php
    $pending = $pendingTargetId ? $employees->firstWhere('id', $pendingTargetId) : null;
@endphp

<div class="mb-6">
    <h1 class="text-xl font-semibold tracking-tight">Pendaftaran Sidik Jari Karyawan</h1>
    <p class="mt-1 text-sm text-ink-500">
        Karyawan masuk dengan sidik jari (Windows Hello) dan <strong>tidak pernah menerima password</strong>.
        Anda (admin) yang mendaftarkan akun sekaligus sidik jarinya — karyawan tidak perlu tahu kredensial apa pun.
    </p>
</div>

@if($pending)
    {{-- Kunci sesi aktif: hanya satu karyawan yang bisa didaftarkan pada satu waktu. --}}
    <section class="card mb-5 border-2 border-emerald-300">
        <div class="card-header bg-emerald-50">
            <h2 class="card-title">Perangkat karyawan: {{ $pending->username }}</h2>
            <span class="badge-pass">sesi terkunci</span>
        </div>
        <div class="card-body">
            <p class="text-sm text-ink-600">
                Sekarang tempelkan <strong>sidik jari karyawan</strong> pada sensor di perangkatnya.
                Pastikan yang menempel bukan sidik jari Anda.
            </p>

            <form id="form-enroll" class="mt-4 flex flex-wrap items-end gap-3">
                <div class="grow">
                    <label class="label" for="enroll-name">Nama passkey</label>
                    <input class="input" id="enroll-name" name="name" type="text" maxlength="255"
                           value="Windows Hello — {{ $pending->username }}">
                </div>
                <button type="submit" id="btn-enroll" class="btn-primary">
                    Daftarkan sidik jari sekarang
                </button>
            </form>

            <form method="POST" action="{{ route('employees.cancel') }}" class="mt-3">
                @csrf
                <button type="submit" class="btn-secondary btn-sm">Batalkan</button>
            </form>

            <div id="enroll-msg" class="mt-3 hidden rounded-md border px-3 py-2 text-sm"></div>
        </div>
    </section>
@endif

<div class="grid gap-5 lg:grid-cols-3">
    <section class="card lg:col-span-2">
        <div class="card-header">
            <h2 class="card-title">Karyawan terdaftar</h2>
            <span class="badge-info">{{ $employees->count() }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Nama</th>
                        <th>Passkey</th>
                        <th class="w-40"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($employees as $employee)
                        <tr>
                            <td class="font-medium">{{ $employee->username }}</td>
                            <td>{{ $employee->name }}</td>
                            <td>
                                @forelse($employee->passkeys as $passkey)
                                    <span class="badge-info mr-1">{{ $passkey->name }}</span>
                                @empty
                                    <span class="badge-warn">belum ada</span>
                                @endforelse
                            </td>
                            <td class="space-x-2 whitespace-nowrap">
                                <form method="POST" action="{{ route('employees.prepare', $employee) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="btn-primary btn-sm">
                                        {{ $employee->passkeys->isEmpty() ? 'Daftarkan sidik jari' : 'Tambah passkey' }}
                                    </button>
                                </form>
                                @foreach($employee->passkeys as $passkey)
                                    <button type="button" class="btn-danger btn-sm" data-revoke="{{ $passkey->id }}">Cabut</button>
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-ink-400">Belum ada karyawan. Buat akun di panel kanan.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-body border-t border-ink-200 text-xs text-ink-400">
            Akun karyawan dibuat tanpa password dan tanpa email yang dapat dipakai. Satu-satunya cara masuk adalah passkey yang didaftarkan di sini.
        </div>
    </section>

    <section class="card">
        <div class="card-header"><h2 class="card-title">Buat akun karyawan</h2></div>
        <div class="card-body">
            <p class="mb-4 text-sm text-ink-500">
                Isi username dan nama. Password tidak diminta karena tidak akan pernah ada.
            </p>

            <form method="POST" action="{{ route('employees.create') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="label" for="employee-username">Username</label>
                    <input class="input" id="employee-username" name="username" type="text" maxlength="64"
                           required placeholder="budi" value="{{ old('username') }}">
                </div>
                <div>
                    <label class="label" for="employee-name">Nama tampil</label>
                    <input class="input" id="employee-name" name="name" type="text" maxlength="120"
                           required placeholder="Budi Santoso" value="{{ old('name') }}">
                </div>
                <button type="submit" class="btn-primary w-full">Buat akun (tanpa password)</button>
            </form>

            <div class="mt-5 space-y-2 rounded-md border border-ink-200 bg-ink-50 p-3 text-xs text-ink-500">
                <p><strong class="text-ink-600">Cara kerja:</strong> buat akun → kunci sesi ke karyawan itu → tempelkan sidik jarinya → passkey tersimpan sebagai milik karyawan, bukan milik Anda.</p>
                <p><strong class="text-ink-600">Resident key</strong> diwajibkan sehingga karyawan bisa masuk tanpa mengetik username.</p>
                <p><strong class="text-ink-600">Otorisasi:</strong> target selalu diambil dari database, bukan dari data yang dikirim browser.</p>
            </div>
        </div>
    </section>
</div>

<section class="card mt-5">
    <div class="card-body">
        <div class="text-sm text-ink-600">
            <strong>Session fingerprint admin:</strong> <span class="mono-val">{{ $sessionFingerprint ?? '—' }}</span>
        </div>
        <p class="mt-1 text-xs text-ink-400">Dipakai hanya untuk membedakan sesi di dalam log diagnostik.</p>
    </div>
</section>
@endsection

@push('scripts')
<script type="module">
    const msg = document.getElementById('enroll-msg');
    const form = document.getElementById('form-enroll');
    const btn = document.getElementById('btn-enroll');
    const targetId = {{ $pending ? (int) $pending->getKey() : 0 }};

    const show = (text, ok) => {
        if (!msg) return;
        msg.className = 'mt-3 rounded-md border px-3 py-2 text-sm ' +
            (ok ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800');
        msg.textContent = text;
    };

    const withConfirmation = async (action) => {
        try {
            return await action();
        } catch (e) {
            if (e?.status !== 423) throw e;
            show('Perlu konfirmasi ulang — verifikasi sidik jari Anda…', true);
            await window.helloTestPasskeys.confirmWithPasskey();
            return action();
        }
    };

    if (form) {
        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();

            const name = document.getElementById('enroll-name').value.trim();
            btn.disabled = true;

            try {
                await withConfirmation(() => window.helloTestPasskeys.enrollEmployeePasskey(targetId, name));
                show('Sidik jari karyawan tersimpan. Memuat ulang…', true);
                setTimeout(() => window.location.reload(), 900);
            } catch (e) {
                show(e?.message ?? 'Pendaftaran sidik jari gagal.', false);
                btn.disabled = false;
            }
        });
    }

    document.querySelectorAll('[data-revoke]').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!window.confirm('Cabut passkey karyawan ini? Akses karyawan akan hilang sampai didaftarkan ulang.')) return;

            const id = button.getAttribute('data-revoke');
            button.disabled = true;

            try {
                await withConfirmation(() => window.helloTestPasskeys.revokeEmployeePasskey(id));
                show('Passkey dicabut. Memuat ulang…', true);
                setTimeout(() => window.location.reload(), 900);
            } catch (e) {
                show(e?.message ?? 'Pencabutan gagal.', false);
                button.disabled = false;
            }
        });
    });
</script>
@endpush
