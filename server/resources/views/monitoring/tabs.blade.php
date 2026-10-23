{{-- Tab antar halaman Monitoring; tab fitur yang dimatikan admin atau di luar hak akses tidak ditampilkan. --}}
<nav class="mon-tabs" aria-label="Bagian monitoring">
    <a href="{{ route('devices.index') }}" @class(['is-active' => ($active ?? '') === 'devices']) @if(($active ?? '') === 'devices') aria-current="page" @endif>Perangkat</a>
    @feature('bandwidth')
        <a href="{{ route('monitor.bandwidth') }}" @class(['is-active' => ($active ?? '') === 'bandwidth']) @if(($active ?? '') === 'bandwidth') aria-current="page" @endif>Bandwidth</a>
    @endfeature
    @feature('network')
        <a href="{{ route('network.index') }}" @class(['is-active' => ($active ?? '') === 'network']) @if(($active ?? '') === 'network') aria-current="page" @endif>Jaringan</a>
    @endfeature
    @can('manage-fleet')
        @feature('browsing')
            <a href="{{ route('monitor.browsing') }}" @class(['is-active' => ($active ?? '') === 'browsing']) @if(($active ?? '') === 'browsing') aria-current="page" @endif>Browsing</a>
        @endfeature
    @endcan
    <button type="button" class="btn btn-small fs-toggle" data-fullscreen-toggle aria-pressed="false" title="Tampilkan layar penuh untuk monitor (Esc untuk keluar)">Layar penuh</button>
</nav>
