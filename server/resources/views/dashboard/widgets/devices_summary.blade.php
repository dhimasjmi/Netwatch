<a class="card card-link" href="{{ route('devices.index') }}"><div class="card-label">Total perangkat</div><div class="card-value">{{ $d['total'] }}</div></a>
<a class="card card-link" href="{{ route('devices.index', ['status' => 'online']) }}"><div class="card-label">Online</div><div class="card-value ok">{{ $d['online'] }}</div></a>
<a class="card card-link" href="{{ route('devices.index', ['status' => 'offline']) }}"><div class="card-label">Offline</div><div class="card-value {{ $d['offline'] > 0 ? 'bad' : '' }}">{{ $d['offline'] }}</div></a>
<div class="card"><div class="card-label">Perintah berjalan</div><div class="card-value">{{ $d['pending'] }}</div></div>
