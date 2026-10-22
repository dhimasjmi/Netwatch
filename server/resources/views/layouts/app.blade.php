<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'NetWatch') · NetWatch</title>
    <link rel="stylesheet" href="{{ asset('css/netwatch.css') }}">
    <script src="{{ asset('js/netwatch.js') }}" defer></script>
</head>
<body>
<a class="skip-link" href="#content">Lewati ke konten</a>
<header class="topbar">
    <a class="brand" href="{{ route('dashboard') }}">NetWatch</a>
    <nav aria-label="Menu utama">
        <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')]) @if(request()->routeIs('dashboard')) aria-current="page" @endif>Dashboard</a>
        <a href="{{ route('devices.index') }}" @class(['active' => request()->routeIs('devices.*')]) @if(request()->routeIs('devices.*')) aria-current="page" @endif>Perangkat</a>
        <a href="{{ route('network.index') }}" @class(['active' => request()->routeIs('network.*')]) @if(request()->routeIs('network.*')) aria-current="page" @endif>Jaringan</a>
        <a href="{{ route('events.index') }}" @class(['active' => request()->routeIs('events.*')]) @if(request()->routeIs('events.*')) aria-current="page" @endif>Event</a>
        <a href="{{ route('reports.uptime') }}" @class(['active' => request()->routeIs('reports.*')]) @if(request()->routeIs('reports.*')) aria-current="page" @endif>Laporan Uptime</a>
        @can('handle-tickets')
            <a href="{{ route('tickets.index') }}" @class(['active' => request()->routeIs('tickets.*')]) @if(request()->routeIs('tickets.*')) aria-current="page" @endif>Tiket</a>
        @endcan
        @can('manage-fleet')
            <details class="nav-menu">
                <summary @class(['active' => request()->routeIs('groups.*', 'tokens.*', 'users.*', 'policies.*', 'alerts.*', 'assets.*', 'browsing.*', 'security.*', 'audit.*')])>Administrasi</summary>
                <div class="nav-menu-list">
            <a href="{{ route('groups.index') }}" @class(['active' => request()->routeIs('groups.*')]) @if(request()->routeIs('groups.*')) aria-current="page" @endif>Grup</a>
            <a href="{{ route('tokens.index') }}" @class(['active' => request()->routeIs('tokens.*')]) @if(request()->routeIs('tokens.*')) aria-current="page" @endif>Token Pendaftaran</a>
            <a href="{{ route('users.index') }}" @class(['active' => request()->routeIs('users.*')]) @if(request()->routeIs('users.*')) aria-current="page" @endif>Pengguna</a>
            <a href="{{ route('policies.index') }}" @class(['active' => request()->routeIs('policies.*')]) @if(request()->routeIs('policies.*')) aria-current="page" @endif>Policies</a>
            <a href="{{ route('alerts.index') }}" @class(['active' => request()->routeIs('alerts.*')]) @if(request()->routeIs('alerts.*')) aria-current="page" @endif>Alert</a>
            <a href="{{ route('assets.index') }}" @class(['active' => request()->routeIs('assets.*')]) @if(request()->routeIs('assets.*')) aria-current="page" @endif>Inventaris</a>
            <a href="{{ route('browsing.index') }}" @class(['active' => request()->routeIs('browsing.*')]) @if(request()->routeIs('browsing.*')) aria-current="page" @endif>Browsing</a>
            <a href="{{ route('security.index') }}" @class(['active' => request()->routeIs('security.*')]) @if(request()->routeIs('security.*')) aria-current="page" @endif>Keamanan</a>
            <a href="{{ route('audit.index') }}" @class(['active' => request()->routeIs('audit.*')]) @if(request()->routeIs('audit.*')) aria-current="page" @endif>Audit Log</a>
                </div>
            </details>
        @endcan
    </nav>
    <form method="POST" action="{{ route('logout') }}" class="logout">
        @csrf
        <span class="muted">{{ auth()->user()->name }} ({{ auth()->user()->role->label() }})</span>
        <button type="submit" class="btn btn-small">Keluar</button>
    </form>
</header>

<main id="content" class="container" tabindex="-1" @yield('main-attrs')>
    @if (session('status'))
        <div class="alert alert-ok" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error" role="alert">
            @foreach ($errors->all() as $message)
                <div>{{ $message }}</div>
            @endforeach
        </div>
    @endif

    @yield('content')
</main>

<p class="refresh-note muted" id="refresh-note" hidden></p>
@stack('scripts')
<div id="toast" class="toast" hidden role="status"></div>
</body>
</html>
