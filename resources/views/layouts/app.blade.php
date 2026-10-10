<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Diagnostik') · Hello Test PoC</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full">
<div class="min-h-screen">
    <header class="border-b border-ink-200 bg-white">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 sm:px-6">
            <a href="{{ route('landing') }}" class="flex items-center gap-2.5">
                <span class="grid size-7 place-items-center rounded-md bg-ink-900 text-xs font-bold text-white">HT</span>
                <span class="text-sm font-semibold tracking-tight">Hello Test <span class="text-ink-400">PoC</span></span>
            </a>

            <nav class="flex flex-wrap items-center gap-x-1 text-sm">
                <a href="{{ route('landing') }}" class="rounded px-2.5 py-1.5 {{ request()->routeIs('landing') ? 'bg-ink-100 font-medium text-ink-900' : 'text-ink-600 hover:bg-ink-100' }}">Beranda</a>

                @auth
                    <a href="{{ route('dashboard') }}" class="rounded px-2.5 py-1.5 {{ request()->routeIs('dashboard') ? 'bg-ink-100 font-medium text-ink-900' : 'text-ink-600 hover:bg-ink-100' }}">Dashboard</a>
                    <a href="{{ route('passkeys.index') }}" class="rounded px-2.5 py-1.5 {{ request()->routeIs('passkeys.*') ? 'bg-ink-100 font-medium text-ink-900' : 'text-ink-600 hover:bg-ink-100' }}">Windows Hello</a>
                    @if(auth()->user()?->isAdmin())
                        <a href="{{ route('biometric.index') }}" class="rounded px-2.5 py-1.5 {{ request()->routeIs('biometric.*') ? 'bg-ink-100 font-medium text-ink-900' : 'text-ink-600 hover:bg-ink-100' }}">Sidik Jari</a>
                    @endif
                    <a href="{{ route('test-results.index') }}" class="rounded px-2.5 py-1.5 {{ request()->routeIs('test-results.*') ? 'bg-ink-100 font-medium text-ink-900' : 'text-ink-600 hover:bg-ink-100' }}">Hasil Pengujian</a>
                    <a href="{{ route('documentation') }}" class="rounded px-2.5 py-1.5 {{ request()->routeIs('documentation') ? 'bg-ink-100 font-medium text-ink-900' : 'text-ink-600 hover:bg-ink-100' }}">Dokumentasi</a>

                    @if(auth()->user()->isAdmin())
                        <a href="{{ route('employees.index') }}" class="rounded px-2.5 py-1.5 {{ request()->routeIs('employees.*') ? 'bg-ink-100 font-medium text-ink-900' : 'text-ink-600 hover:bg-ink-100' }}">Karyawan</a>
                        <a href="{{ route('admin.test') }}" class="rounded px-2.5 py-1.5 {{ request()->routeIs('admin.test') ? 'bg-ink-100 font-medium text-ink-900' : 'text-ink-600 hover:bg-ink-100' }}">Admin Test</a>
                    @endif
                    @if(auth()->user()->isSupervisor())
                        <a href="{{ route('supervisor.test') }}" class="rounded px-2.5 py-1.5 {{ request()->routeIs('supervisor.test') ? 'bg-ink-100 font-medium text-ink-900' : 'text-ink-600 hover:bg-ink-100' }}">Supervisor Test</a>
                    @endif
                @endauth
            </nav>

            <div class="ml-auto flex items-center gap-3">
                <div class="hidden items-center gap-1 rounded-md border border-ink-200 p-0.5 sm:flex">
                    <a href="{{ route('locale', 'id') }}" class="rounded px-1.5 py-0.5 text-xs {{ app()->getLocale() === 'id' ? 'bg-ink-900 text-white' : 'text-ink-500 hover:bg-ink-100' }}">ID</a>
                    <a href="{{ route('locale', 'en') }}" class="rounded px-1.5 py-0.5 text-xs {{ app()->getLocale() === 'en' ? 'bg-ink-900 text-white' : 'text-ink-500 hover:bg-ink-100' }}">EN</a>
                </div>

                @auth
                    <span class="hidden text-right leading-tight sm:block">
                        <span class="block text-xs font-medium text-ink-900">{{ auth()->user()->username }}</span>
                        <span class="block text-[11px] text-ink-400">{{ auth()->user()->role->label() }}</span>
                    </span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn-secondary btn-sm">Keluar</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="btn-primary btn-sm">Masuk</a>
                @endauth
            </div>
        </div>
    </header>

    @if(session('status') || $errors->any())
        <div class="mx-auto max-w-6xl px-4 pt-4 sm:px-6">
            @if(session('status'))
                <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800">{{ session('status') }}</div>
            @endif
            @if($errors->any())
                <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm text-rose-800">
                    <ul class="list-inside list-disc space-y-0.5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif

    <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-8">
        @yield('content')
    </main>

    <footer class="mx-auto max-w-6xl px-4 pb-10 text-xs text-ink-400 sm:px-6">
        Proof of Concept terisolasi untuk pengujian Windows Hello / WebAuthn. Tidak terhubung ke dashboard produksi.
    </footer>
</div>

@stack('scripts')
</body>
</html>
