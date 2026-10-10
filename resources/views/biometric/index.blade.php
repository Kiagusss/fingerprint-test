@extends('layouts.app')

@section('title', 'Perangkat Sidik Jari')

@section('content')
<div class="mb-6">
    <h1 class="text-xl font-semibold tracking-tight">Perangkat Sidik Jari</h1>
    <p class="mt-1 text-sm text-ink-500">
        Sidik jari di sini hanya <strong>mengidentifikasi</strong> siapa yang berdiri di depan PC bersama —
        bukan membuktikan identitasnya. Login tetap memerlukan password atau passkey milik karyawan itu sendiri.
    </p>
</div>

@if($newDeviceSecret)
    <section class="card mb-5 border-2 border-emerald-300">
        <div class="card-header bg-emerald-50">
            <h2 class="card-title">Secret perangkat — salin sekarang</h2>
            <span class="badge-pass">tampil sekali</span>
        </div>
        <div class="card-body">
            <p class="mb-3 text-sm text-ink-600">
                Masukkan nilai ini ke berkas konfigurasi agen di PC tersebut. Server tidak menyimpannya
                dalam bentuk terbaca, jadi setelah halaman ini ditutup nilainya tidak dapat ditampilkan lagi —
                hanya bisa diganti dengan membuat perangkat baru.
            </p>
            <div class="space-y-2 text-sm">
                <div><span class="text-ink-500">UUID perangkat:</span> <span class="mono-val">{{ $newDeviceUuid }}</span></div>
                <div><span class="text-ink-500">Secret:</span> <span class="mono-val break-all">{{ $newDeviceSecret }}</span></div>
            </div>
        </div>
    </section>
@endif

<div class="grid gap-5 lg:grid-cols-3">
    <section class="card lg:col-span-2">
        <div class="card-header">
            <h2 class="card-title">Perangkat terdaftar</h2>
            <span class="badge-info">{{ $devices->count() }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Platform</th>
                        <th>Jari aktif</th>
                        <th>Terakhir terlihat</th>
                        <th>Status</th>
                        <th class="w-32"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($devices as $device)
                        <tr>
                            <td>
                                <div class="font-medium">{{ $device->name }}</div>
                                <div class="mono-val">{{ $device->uuid }}</div>
                                @if($device->host_label)
                                    <div class="text-xs text-ink-400">host: {{ $device->host_label }}</div>
                                @endif
                            </td>
                            <td>{{ $device->platform ?? '—' }}</td>
                            <td>{{ $device->enrollments_count }}</td>
                            <td class="text-ink-500">
                                {{ $device->last_seen_at?->diffForHumans() ?? 'belum pernah' }}
                                @if($device->last_seen_ip)
                                    <div class="mono-val">{{ $device->last_seen_ip }}</div>
                                @endif
                            </td>
                            <td>
                                @if($device->active)
                                    <span class="badge-pass">aktif</span>
                                @else
                                    <span class="badge-warn">nonaktif</span>
                                @endif
                            </td>
                            <td>
                                <form method="POST" action="{{ route('biometric.toggle', $device) }}" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn-secondary btn-sm">
                                        {{ $device->active ? 'Nonaktifkan' : 'Aktifkan' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-ink-400">
                                Belum ada perangkat. Daftarkan satu di panel kanan.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-body border-t border-ink-200 text-xs text-ink-400">
            Agen membuktikan dirinya dengan HMAC atas secret perangkat pada setiap permintaan.
            Nonaktifkan perangkat bila PC-nya hilang atau dipakai di luar keperluan.
        </div>
    </section>

    <section class="card">
        <div class="card-header"><h2 class="card-title">Daftarkan perangkat baru</h2></div>
        <div class="card-body">
            <p class="mb-4 text-sm text-ink-500">
                Satu perangkat = satu agen di satu PC bersama. Buat satu baris per PC.
            </p>

            <form method="POST" action="{{ route('biometric.store') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="label" for="device-name">Nama perangkat</label>
                    <input class="input" id="device-name" name="name" type="text" maxlength="120"
                           required placeholder="PC Resepsionis" value="{{ old('name') }}">
                </div>
                <div>
                    <label class="label" for="device-host">Label host (opsional)</label>
                    <input class="input" id="device-host" name="host_label" type="text" maxlength="64"
                           placeholder="RESEPSI-01" value="{{ old('host_label') }}">
                </div>
                <div>
                    <label class="label" for="device-platform">Platform</label>
                    <select class="input" id="device-platform" name="platform" required>
                        <option value="windows" @selected(old('platform') === 'windows')>Windows</option>
                        <option value="linux" @selected(old('platform') === 'linux')>Linux</option>
                        <option value="macos" @selected(old('platform') === 'macos')>macOS</option>
                    </select>
                </div>
                <button type="submit" class="btn-primary w-full">Buat perangkat</button>
            </form>

            <div class="mt-5 space-y-2 rounded-md border border-ink-200 bg-ink-50 p-3 text-xs text-ink-500">
                <p><strong class="text-ink-600">Kenapa tidak dikunci ke Windows Hello:</strong> container Windows Hello
                    melekat pada akun Windows, bukan pada orang. Di PC bersama semua karyawan memakai akun Windows
                    yang sama, sehingga Hello tidak pernah bisa menjawab "ini siapa".</p>
                <p><strong class="text-ink-600">Data biometrik:</strong> template disimpan terenkripsi, hanya dibuat
                    setelah ada persetujuan pemiliknya, dan dapat dicabut dari halaman ini.</p>
            </div>
        </div>
    </section>
</div>

<section class="card mt-5">
    <div class="card-header">
        <h2 class="card-title">Pendaftaran sidik jari aktif</h2>
        <span class="badge-info">{{ $activeEnrollments->count() }}</span>
    </div>
    <div class="overflow-x-auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Pengguna</th>
                    <th>Jari</th>
                    <th>Mutu</th>
                    <th>Template</th>
                    <th>Didaftarkan di</th>
                    <th>Persetujuan</th>
                    <th class="w-24"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($activeEnrollments as $enrollment)
                    <tr>
                        <td>
                            <div class="font-medium">{{ $enrollment->user?->username }}</div>
                            <div class="text-xs text-ink-400">{{ $enrollment->user?->name }}</div>
                        </td>
                        <td>{{ $enrollment->finger }}</td>
                        <td>{{ $enrollment->quality }}%</td>
                        <td class="mono-val">
                            @if($enrollment->template !== null)
                                di server + {{ $enrollment->template_ref }}
                            @else
                                {{ $enrollment->template_ref }}
                            @endif
                        </td>
                        <td class="text-ink-500">{{ $enrollment->sourceDevice?->name ?? '—' }}</td>
                        <td class="text-ink-500">{{ $enrollment->consent_at?->format('Y-m-d H:i') }}</td>
                        <td>
                            <form method="POST" action="{{ route('biometric.revoke', $enrollment) }}" class="inline">
                                @csrf
                                <button type="submit" class="btn-danger btn-sm">Cabut</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-ink-400">
                            Belum ada sidik jari terdaftar. Agen di PC yang akan melaporkannya ke endpoint
                            <span class="mono-val">POST /api/biometric/enrollments</span>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

@if($revokedEnrollments->isNotEmpty())
    <section class="card mt-5">
        <div class="card-header">
            <h2 class="card-title">Riwayat pencabutan</h2>
            <span class="badge-warn">{{ $revokedEnrollments->count() }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Pengguna</th>
                        <th>Jari</th>
                        <th>Dicabut pada</th>
                        <th>Oleh</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($revokedEnrollments as $enrollment)
                        <tr>
                            <td>{{ $enrollment->user?->username ?? '—' }}</td>
                            <td>{{ $enrollment->finger }}</td>
                            <td class="text-ink-500">{{ $enrollment->revoked_at?->format('Y-m-d H:i') }}</td>
                            <td class="text-ink-500">{{ $enrollment->revokedBy?->username ?? 'perangkat' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

<section class="card mt-5">
    <div class="card-body">
        <div class="text-sm text-ink-600">
            <strong>Session fingerprint admin:</strong> <span class="mono-val">{{ $sessionFingerprint ?? '—' }}</span>
        </div>
        <p class="mt-1 text-xs text-ink-400">Dipakai hanya untuk membedakan sesi di dalam log diagnostik.</p>
    </div>
</section>
@endsection
