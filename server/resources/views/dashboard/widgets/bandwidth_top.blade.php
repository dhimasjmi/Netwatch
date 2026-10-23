@if ($d === null)
    <p class="muted">Data belum bisa dimuat.</p>
@else
    @forelse ($d['table']['rows'] as $row)
        <div class="dash-bar-row">
            <a href="{{ $row['url'] }}" class="dash-bar-name">{{ $row['name'] }}</a>
            <progress class="mon-usage" max="100" value="{{ $row['usage_pct'] }}" aria-label="{{ $row['usage_pct'] }}% dari terbesar"></progress>
            <span class="muted dash-bar-val">↓ {{ round($row['rx_bps'] / 1_000_000, 1) }} · ↑ {{ round($row['tx_bps'] / 1_000_000, 1) }} Mbps</span>
        </div>
    @empty
        <p class="muted">Belum ada data bandwidth.</p>
    @endforelse
@endif
