{{-- Timeline online/offline. Variabel: $segments, $range. SVG dipakai karena CSP melarang gaya inline. --}}
@php($span = max(1, $range->seconds()))
@php($fromTs = $range->from->getTimestamp())
<svg class="timeline" viewBox="0 0 1000 24" preserveAspectRatio="none" role="img" aria-label="Timeline status {{ $range->label() }}">
    <rect x="0" y="0" width="1000" height="24" class="timeline-gap"/>
    @foreach ($segments as $segment)
        <rect x="{{ round(($segment['start'] - $fromTs) / $span * 1000, 2) }}" y="0"
              width="{{ max(1, round(($segment['end'] - $segment['start']) / $span * 1000, 2)) }}" height="24"
              class="{{ $segment['down'] ? 'timeline-down' : 'timeline-up' }}"/>
    @endforeach
</svg>
<div class="timeline-legend muted">
    <span><i class="dot dot-up"></i> Online</span>
    <span><i class="dot dot-down"></i> Offline</span>
    <span><i class="dot dot-gap"></i> Tanpa data</span>
</div>
