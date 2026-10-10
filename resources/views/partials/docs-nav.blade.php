@php
    $sections = [
        'lingkungan' => 'Lingkungan',
        'mode' => 'Mode Identitas',
        'https' => 'HTTPS & RP ID',
        'akun' => 'Akun Pengujian',
        'uji' => 'Prosedur Uji',
        'keamanan' => 'Kontrol Keamanan',
        'rollback' => 'Rollback',
    ];
@endphp

<nav class="mb-5 flex flex-wrap gap-2">
    @foreach($sections as $id => $label)
        <a href="#{{ $id }}" class="btn-secondary btn-sm">{{ $label }}</a>
    @endforeach
</nav>
