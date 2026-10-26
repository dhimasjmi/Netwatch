<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'NetWatch') · NetWatch</title>
    <link rel="stylesheet" href="{{ asset('css/netwatch.css') }}">
    <script src="{{ asset('js/netwatch.js') }}" defer></script>
    <script src="{{ asset('js/fullscreen.js') }}" defer></script>
</head>
<body>
<a class="skip-link" href="#content">Lewati ke konten</a>
<header class="topbar">
    <a class="brand" href="{{ route('dashboard') }}">NetWatch</a>
    <nav aria-label="Menu utama">
        <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')]) @if(request()->routeIs('dashboard')) aria-current="page" @endif>Dashboard</a>
        <details class="nav-menu">
            <summary @class(['active' => request()->routeIs('devices.*', 'monitor.*', 'network.*', 'events.*', 'reports.*', 'browsing.*')])>Monitoring</summary>
            <div class="nav-menu-list">
                <a href="{{ route('devices.index') }}" @class(['active' => request()->routeIs('devices.*') && ! request()->routeIs('devices.bandwidth*')]) @if(request()->routeIs('devices.index')) aria-current="page" @endif>Perangkat</a>
                @feature('bandwidth')
                    <a href="{{ route('monitor.bandwidth') }}" @class(['active' => request()->routeIs('monitor.bandwidth*')]) @if(request()->routeIs('monitor.bandwidth')) aria-current="page" @endif>Bandwidth</a>
                @endfeature
                @can('manage-fleet')
                    @feature('browsing')
                        <a href="{{ route('monitor.browsing') }}" @class(['active' => request()->routeIs('monitor.browsing*', 'browsing.*')]) @if(request()->routeIs('monitor.browsing')) aria-current="page" @endif>Browsing</a>
                    @endfeature
                @endcan
                @feature('network')
                    <a href="{{ route('network.index') }}" @class(['active' => request()->routeIs('network.*')]) @if(request()->routeIs('network.*')) aria-current="page" @endif>Jaringan</a>
                @endfeature
                <a href="{{ route('events.index') }}" @class(['active' => request()->routeIs('events.*')]) @if(request()->routeIs('events.*')) aria-current="page" @endif>Event</a>
                <a href="{{ route('reports.uptime') }}" @class(['active' => request()->routeIs('reports.*')]) @if(request()->routeIs('reports.*')) aria-current="page" @endif>Laporan Uptime</a>
            </div>
        </details>
        @can('handle-tickets')
            @feature('tickets')
                <a href="{{ route('tickets.index') }}" @class(['active' => request()->routeIs('tickets.*')]) @if(request()->routeIs('tickets.*')) aria-current="page" @endif>Tiket</a>
            @endfeature
        @endcan
        @can('manage-fleet')
            <details class="nav-menu">
                <summary @class(['active' => request()->routeIs('groups.*', 'tokens.*', 'users.*', 'policies.*', 'alerts.*', 'assets.*', 'features.*', 'database.*', 'security.*', 'audit.*')])>Administrasi</summary>
                <div class="nav-menu-list">
            <a href="{{ route('groups.index') }}" @class(['active' => request()->routeIs('groups.*')]) @if(request()->routeIs('groups.*')) aria-current="page" @endif>Grup</a>
            <a href="{{ route('tokens.index') }}" @class(['active' => request()->routeIs('tokens.*')]) @if(request()->routeIs('tokens.*')) aria-current="page" @endif>Token Pendaftaran</a>
            <a href="{{ route('users.index') }}" @class(['active' => request()->routeIs('users.*')]) @if(request()->routeIs('users.*')) aria-current="page" @endif>Pengguna</a>
            <a href="{{ route('policies.index') }}" @class(['active' => request()->routeIs('policies.*')]) @if(request()->routeIs('policies.*')) aria-current="page" @endif>Policies</a>
            @feature('threshold_alerts')
                <a href="{{ route('alerts.index') }}" @class(['active' => request()->routeIs('alerts.*')]) @if(request()->routeIs('alerts.*')) aria-current="page" @endif>Alert</a>
            @endfeature
            @feature('inventory')
                <a href="{{ route('assets.index') }}" @class(['active' => request()->routeIs('assets.*')]) @if(request()->routeIs('assets.*')) aria-current="page" @endif>Inventaris</a>
            @endfeature
            <a href="{{ route('features.index') }}" @class(['active' => request()->routeIs('features.*')]) @if(request()->routeIs('features.*')) aria-current="page" @endif>Fitur</a>
            <a href="{{ route('database.index') }}" @class(['active' => request()->routeIs('database.*')]) @if(request()->routeIs('database.*')) aria-current="page" @endif>Database &amp; Backup</a>
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
