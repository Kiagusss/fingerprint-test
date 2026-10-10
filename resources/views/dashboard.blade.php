@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
@php
    // Scoped to the signed-in account: one test profile must never surface the
    // other profile's identifiers, even in a shared diagnostic log.
    $recent = \App\Models\TestLog::query()
        ->with('user')
        ->where(fn ($q) => $q->where('user_id', $user->id)->orWhereNull('user_id'))
        ->latest('id')
        ->limit(12)
        ->get();
    $eventTone = fn (string $e) => str_contains($e, 'fail') || str_contains($e, 'denied') ? 'badge-fail'
        : (str_contains($e, 'success') || str_contains($e, 'registered') ? 'badge-pass' : 'badge-info');
@endphp

<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-xl font-semibold tracking-tight">Dashboard Diagnostik</h1>
        <p class="mt-1 text-sm text-ink-500">Identitas akun, status sesi, dan aktivitas autentikasi terbaru.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('passkeys.index') }}" class="btn-secondary btn-sm">Kelola Passkey</a>
        <a href="{{ route('test-results.index') }}" class="btn-secondary btn-sm">Hasil Pengujian</a>
    </div>
</div>

<div class="grid gap-5 lg:grid-cols-3">
    <section class="card lg:col-span-1">
        <div class="card-header"><h2 class="card-title">Identitas Akun Aplikasi</h2></div>
        <div class="card-body">
            <dl class="kv !grid-cols-1 gap-y-3">
                <div>
                    <dt>Username</dt>
                    <dd class="mt-0.5 text-base font-semibold">{{ $user->username }}</dd>
                </div>
                <div>
                    <dt>Role (server-side)</dt>
                    <dd class="mt-0.5"><span class="badge-info">{{ $user->role->label() }}</span></dd>
                </div>
                <div>
                    <dt>Email</dt>
                    <dd class="mono-val mt-0.5">{{ $user->email }}</dd>
                </div>
                @if($osAccountLabel)
                <div>
                    <dt>Label akun Windows (manual)</dt>
                    <dd class="mt-0.5"><span class="badge-warn">{{ $osAccountLabel }}</span></dd>
                </div>
                @endif
            </dl>
        </div>
    </section>

    <section class="card lg:col-span-2">
        <div class="card-header">
            <h2 class="card-title">Status Sesi</h2>
            <span class="badge-pass">Sesi aktif</span>
        </div>
        <div class="card-body">
            <dl class="kv">
                <dt>Autentikasi terakhir</dt>
                <dd>{{ $user->last_authenticated_at?->format('Y-m-d H:i:s') ?? '—' }}
                    <span class="text-ink-400">({{ $user->last_authenticated_at?->diffForHumans() ?? '—' }})</span></dd>

                <dt>Metode terakhir</dt>
                <dd class="mono-val">{{ $user->last_auth_method ?? '—' }}</dd>

                <dt>Passkey terdaftar</dt>
                <dd>{{ $passkeys->count() }} kredensial</dd>

                <dt>Session driver</dt>
                <dd class="mono-val">{{ $server['session_driver'] }} · {{ $server['session_lifetime_minutes'] }} menit</dd>
            </dl>

            <div class="mt-4 rounded-md border border-ink-200 bg-ink-50 p-3 text-xs text-ink-500">
                Kembali ke halaman ini selama sesi belum kedaluwarsa tidak memerlukan login ulang. Menutup browser mengakhiri sesi
                kecuali cookie <em>remember</em> aktif. Sesi terpisah untuk tiap profil Chrome.
            </div>
        </div>
    </section>
</div>

@if($user->isAdmin() || $user->isSupervisor())
<section class="mt-5 grid gap-5 lg:grid-cols-2">
    <div class="card">
        <div class="card-header"><h2 class="card-title">Akses Role</h2></div>
        <div class="card-body space-y-3">
            <div class="flex items-center justify-between gap-3 rounded-md border border-ink-200 p-3">
                <div>
                    <div class="text-sm font-medium">Admin Test</div>
                    <div class="text-xs text-ink-400">Hanya role admin</div>
                </div>
                @if($user->isAdmin())
                    <a href="{{ route('admin.test') }}" class="btn-primary btn-sm">Buka</a>
                @else
                    <span class="badge-untested">tidak tersedia</span>
                @endif
            </div>
            <div class="flex items-center justify-between gap-3 rounded-md border border-ink-200 p-3">
                <div>
                    <div class="text-sm font-medium">Supervisor Test</div>
                    <div class="text-xs text-ink-400">Hanya role supervisor</div>
                </div>
                @if($user->isSupervisor())
                    <a href="{{ route('supervisor.test') }}" class="btn-primary btn-sm">Buka</a>
                @else
                    <span class="badge-untested">tidak tersedia</span>
                @endif
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">Passkey Akun Ini</h2></div>
        <div class="card-body">
            @forelse($passkeys as $passkey)
                <div class="flex items-center justify-between gap-3 border-b border-ink-100 py-2 last:border-0">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium">{{ $passkey->name }}</div>
                        <div class="mono-val truncate">{{ $passkey->authenticator ?? 'authenticator tidak dikenal' }}</div>
                    </div>
                    <div class="shrink-0 text-right text-xs text-ink-400">
                        {{ $passkey->last_used_at?->diffForHumans() ?? 'belum dipakai' }}
                    </div>
                </div>
            @empty
                <p class="text-sm text-ink-500">Belum ada passkey. Tambahkan dari halaman Kelola Passkey.</p>
            @endforelse
        </div>
    </div>
</section>
@endif

<section class="card mt-5">
    <div class="card-header">
        <h2 class="card-title">Log Diagnostik Terbaru</h2>
        <span class="text-xs text-ink-400">12 entri terakhir · tanpa secret</span>
    </div>
    <div class="overflow-x-auto">
        <table class="table">
            <thead>
                <tr>
                    <th class="w-40">Waktu</th>
                    <th>Event</th>
                    <th>User</th>
                    <th>Metode</th>
                    <th>Credential fp</th>
                </tr>
            </thead>
            <tbody>
                @forelse($recent as $log)
                    <tr>
                        <td class="mono-val whitespace-nowrap">{{ $log->created_at->format('H:i:s') }}</td>
                        <td><span class="{{ $eventTone($log->event) }}">{{ $log->event }}</span></td>
                        <td>{{ $log->user?->username ?? '—' }}</td>
                        <td class="mono-val">{{ $log->auth_method ?? '—' }}</td>
                        <td class="mono-val">{{ $log->credential_fingerprint ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-ink-400">Belum ada aktivitas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
