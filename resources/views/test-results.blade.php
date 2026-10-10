@extends('layouts.app')

@section('title', 'Hasil Pengujian')

@section('content')
@php
    $tone = fn (?string $s) => match ($s) {
        'pass' => 'badge-pass',
        'fail' => 'badge-fail',
        default => 'badge-untested',
    };
    $label = fn (?string $s) => match ($s) {
        'pass' => 'PASS',
        'fail' => 'FAIL',
        default => 'NOT TESTED',
    };
@endphp

<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-xl font-semibold tracking-tight">Hasil Pengujian</h1>
        <p class="mt-1 text-sm text-ink-500">
            Hasil <strong>otomatis</strong> diisi oleh test suite. Hasil <strong>manual</strong> diisi penguji setelah benar-benar dijalankan.
            Baris tanpa eksekusi tetap <em>NOT TESTED</em>.
        </p>
    </div>
    <div class="flex gap-2">
        <span class="badge-pass">PASS {{ $summary['pass'] }}</span>
        <span class="badge-fail">FAIL {{ $summary['fail'] }}</span>
        <span class="badge-untested">NOT TESTED {{ $summary['not_tested'] }}</span>
        <span class="badge-info">TOTAL {{ $summary['total'] }}</span>
    </div>
</div>

<section class="card mb-5">
    <div class="card-body">
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
            <div class="flex items-center gap-2">
                <span class="dot bg-ink-300"></span>
                <span class="text-ink-600">Auto = dijalankan <code class="mono-val">php artisan test:sync-results</code></span>
            </div>
            <div class="flex items-center gap-2">
                <span class="dot bg-sky-400"></span>
                <span class="text-ink-600">Manual = dijalankan penguji pada Windows A/B</span>
            </div>
        </div>
    </div>
</section>

@foreach($results as $category => $rows)
    <section class="card mb-5">
        <div class="card-header">
            <h2 class="card-title capitalize">{{ str_replace('_', ' ', $category) }}</h2>
            <span class="text-xs text-ink-400">{{ $rows->count() }} kasus</span>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th class="w-1/4">Test case</th>
                        <th class="w-1/5">Hasil diharapkan</th>
                        <th class="w-1/5">Hasil aktual</th>
                        <th class="w-24">Status</th>
                        <th class="w-28">Sumber</th>
                        <th class="w-1/6">Catatan / aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr>
                            <td>
                                <div class="font-medium text-ink-900">{{ $row->title }}</div>
                                <div class="mono-val mt-0.5">{{ $row->case_key }}</div>
                                @if($row->automated_test)
                                    <div class="mono-val mt-0.5 text-ink-400">{{ class_basename(str_replace('\\', '/', $row->automated_test)) }}</div>
                                @endif
                            </td>
                            <td class="text-ink-600">{{ $row->expected }}</td>
                            <td>
                                @if($row->actual)
                                    <span class="text-ink-700">{{ $row->actual }}</span>
                                @else
                                    <span class="text-ink-400">—</span>
                                @endif
                                @if($row->executed_at)
                                    <div class="mono-val mt-1 text-ink-400">{{ $row->executed_at->format('Y-m-d H:i') }}
                                        @if($row->updater) · {{ $row->updater->username }} @endif
                                    </div>
                                @endif
                            </td>
                            <td><span class="{{ $tone($row->status) }}">{{ $label($row->status) }}</span></td>
                            <td>
                                @if($row->source === 'auto')
                                    <span class="badge-info">otomatis</span>
                                @else
                                    <span class="badge-warn">manual</span>
                                @endif
                            </td>
                            <td>
                                @if($row->source === 'manual')
                                    <form method="POST" action="{{ route('test-results.update', $row) }}" class="space-y-2">
                                        @csrf
                                        @method('PATCH')
                                        <select name="status" class="input !py-1 !text-xs">
                                            <option value="not_tested" @selected($row->status === 'not_tested')>NOT TESTED</option>
                                            <option value="pass" @selected($row->status === 'pass')>PASS</option>
                                            <option value="fail" @selected($row->status === 'fail')>FAIL</option>
                                        </select>
                                        <textarea name="actual" rows="2" class="input !py-1 !text-xs" placeholder="Hasil aktual (wajib untuk PASS/FAIL)">{{ $row->actual }}</textarea>
                                        <textarea name="notes" rows="2" class="input !py-1 !text-xs" placeholder="Catatan diagnostik">{{ $row->notes }}</textarea>
                                        <button type="submit" class="btn-secondary btn-sm w-full">Simpan</button>
                                    </form>
                                @else
                                    <span class="text-xs text-ink-400">{{ $row->notes ?? 'Dikelola oleh test suite' }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endforeach

<section class="card">
    <div class="card-header"><h2 class="card-title">Prosedur Pengujian Manual (Windows A &amp; B)</h2></div>
    <div class="card-body">
        <ol class="list-inside list-decimal space-y-1.5 text-sm text-ink-600">
            <li>Login ke <strong>Windows A</strong>.</li>
            <li>Buka Chrome pada profil Windows A.</li>
            <li>Login sebagai <code class="mono-val">test-admin</code>.</li>
            <li>Daftarkan kredensial Windows Hello pada halaman Windows Hello.</li>
            <li>Buka <strong>Admin Test</strong> dan verifikasi identitas.</li>
            <li>Logout untuk menguji login Windows Hello.</li>
            <li>Beralih ke <strong>Windows B</strong>.</li>
            <li>Buka Chrome pada profil Windows B.</li>
            <li>Login sebagai <code class="mono-val">test-supervisor</code>.</li>
            <li>Daftarkan kredensial Windows Hello.</li>
            <li>Buka <strong>Supervisor Test</strong>.</li>
            <li>Beralih kembali ke Windows A — pastikan sesi Admin tetap terpisah.</li>
            <li>Beralih kembali ke Windows B — pastikan sesi Supervisor tetap terpisah.</li>
            <li>Uji logout, sesi kedaluwarsa, restart browser, dan pencabutan kredensial.</li>
        </ol>
        <p class="mt-3 text-xs text-ink-400">
            Pergantian akun Windows <strong>tidak</strong> otomatis menghapus atau mengganti sesi Laravel. Sesi mengikuti cookie pada profil browser.
        </p>
    </div>
</section>
@endsection
