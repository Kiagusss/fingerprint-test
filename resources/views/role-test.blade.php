@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="mb-6">
    <h1 class="text-xl font-semibold tracking-tight">{{ $title }}</h1>
    <p class="mt-1 text-sm text-ink-500">
        Halaman ini hanya dapat dibuka oleh role <span class="font-medium">{{ $role->label() }}</span>. Otorisasi ditegakkan di server dari kolom <code class="mono-val">users.role</code>.
    </p>
</div>

<div class="grid gap-5 lg:grid-cols-2">
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Bukti Identitas (server-side)</h2>
            <span class="badge-pass">Akses diberikan</span>
        </div>
        <div class="card-body">
            <dl class="kv">
                <dt>User terautentikasi</dt>
                <dd class="font-medium">{{ $user->username }}</dd>

                <dt>User ID aplikasi</dt>
                <dd class="mono-val">{{ $user->id }}</dd>

                <dt>Role yang diresolusi server</dt>
                <dd><span class="badge-info">{{ $serverResolvedRole->label() }}</span>
                    <span class="mono-val ml-2">{{ $serverResolvedRole->value }}</span></dd>

                <dt>Halaman yang diminta</dt>
                <dd class="mono-val">role:{{ $role->value }}</dd>

                <dt>Waktu server</dt>
                <dd class="mono-val">{{ $serverTime->format('Y-m-d H:i:s') }}</dd>

                <dt>Session fingerprint</dt>
                <dd class="mono-val">{{ $sessionFingerprint ?? '—' }}</dd>
            </dl>

            <div class="mt-4 rounded-md border border-sky-200 bg-sky-50 p-3 text-xs text-sky-800">
                Role diambil dari basis data, bukan dari permintaan klien. Mengirim <code class="mono-val">?role=admin</code>
                atau header buatan tidak akan mengubah hak akses.
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Windows Hello Akun Ini</h2>
            <span class="badge-info">{{ $passkeys->count() }}</span>
        </div>
        <div class="card-body">
            @forelse($passkeys as $passkey)
                <div class="flex items-center justify-between gap-3 border-b border-ink-100 py-2.5 last:border-0">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium">{{ $passkey->name }}</div>
                        <div class="mono-val">fp: {{ substr(hash('sha256', $passkey->credential_id), 0, 16) }}</div>
                    </div>
                    <div class="shrink-0 text-right text-xs text-ink-400">
                        {{ $passkey->authenticator ?? '—' }}<br>
                        {{ $passkey->last_used_at?->diffForHumans() ?? 'belum dipakai' }}
                    </div>
                </div>
            @empty
                <p class="text-sm text-ink-500">Belum ada kredensial Windows Hello untuk akun ini.</p>
            @endforelse
            <a href="{{ route('passkeys.index') }}" class="btn-secondary btn-sm mt-4">Kelola Windows Hello</a>
        </div>
    </section>
</div>

<section class="card mt-5">
    <div class="card-body">
        <div class="text-sm font-medium">Yang harus diverifikasi penguji</div>
        <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-ink-600">
            <li>Halaman ini terbuka → role server cocok dengan akun yang login.</li>
            <li>Cobalah membuka halaman role lain → harus ditolak (403).</li>
            <li>Pada profil Windows lain, akun aplikasi tetap berbeda dengan session fingerprint berbeda.</li>
        </ul>
    </div>
</section>
@endsection
