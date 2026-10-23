@if ($d === null)
    <div class="card"><div class="card-label">Jaringan</div><div class="muted">Data belum bisa dimuat.</div></div>
@else
    <a class="card card-link" href="{{ route('network.index') }}"><div class="card-label">Perangkat jaringan</div><div class="card-value">{{ $d['total'] }}</div><div class="muted">{{ $d['availability'] !== null ? $d['availability'].'% tersedia' : 'Belum ada data' }}</div></a>
    <a class="card card-link" href="{{ route('network.index', ['status' => 'online']) }}"><div class="card-label">Jaringan online</div><div class="card-value ok">{{ $d['online'] }}</div></a>
    <a class="card card-link" href="{{ route('network.index', ['status' => 'offline']) }}"><div class="card-label">Jaringan offline</div><div class="card-value {{ $d['offline'] > 0 ? 'bad' : '' }}">{{ $d['offline'] }}</div></a>
    <a class="card card-link" href="{{ route('network.index', ['status' => 'warning']) }}"><div class="card-label">Respons lambat</div><div class="card-value {{ $d['slow'] > 0 ? 'warn' : '' }}">{{ $d['slow'] }}</div></a>
@endif
