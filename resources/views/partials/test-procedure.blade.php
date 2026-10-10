@php
    $steps = [
        'Login ke Windows A.',
        'Buka Chrome pada profil Windows A.',
        'Login sebagai test-admin (username + password).',
        'Daftarkan passkey melalui Windows Hello pada halaman Passkey.',
        'Buka halaman Admin Test dan verifikasi identitas.',
        'Logout untuk menguji login passkey.',
        'Beralih ke Windows B.',
        'Buka Chrome pada profil Windows B.',
        'Login sebagai test-supervisor.',
        'Daftarkan passkey melalui Windows Hello.',
        'Buka halaman Supervisor Test.',
        'Beralih kembali ke Windows A — periksa sesi Admin tetap terpisah.',
        'Beralih kembali ke Windows B — periksa sesi Supervisor tetap terpisah.',
        'Uji logout, sesi kedaluwarsa, restart browser, dan pencabutan passkey.',
    ];
@endphp

<ol class="space-y-1.5">
    @foreach($steps as $i => $step)
        <li class="flex gap-3 text-sm text-ink-600">
            <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full border border-ink-200 bg-white text-[11px] font-semibold text-ink-500">
                {{ $i + 1 }}
            </span>
            <span>{!! str_replace(['test-admin', 'test-supervisor'], ['<code class="mono-val">test-admin</code>', '<code class="mono-val">test-supervisor</code>'], e($step)) !!}</span>
        </li>
    @endforeach
</ol>

<div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
    Pergantian akun Windows <strong>tidak</strong> otomatis menghapus atau mengganti sesi Laravel.
    Sesi mengikuti cookie pada profil browser masing-masing.
</div>
