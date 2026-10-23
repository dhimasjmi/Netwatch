@if ($d === null)
    <p class="muted">Data belum bisa dimuat.</p>
@else
    @forelse (array_slice($d['top_sites'], 0, 5) as $site)
        <div class="dash-bar-row">
            <span class="dash-bar-name">{{ $site['host'] }}</span>
            <progress class="mon-usage" max="100" value="{{ $site['share_pct'] }}" aria-label="{{ $site['share_pct'] }}%"></progress>
            <span class="muted dash-bar-val">{{ number_format($site['share_pct'], 1) }}%</span>
        </div>
    @empty
        <p class="muted">Belum ada aktivitas browsing pada 1 jam terakhir.</p>
    @endforelse
@endif
