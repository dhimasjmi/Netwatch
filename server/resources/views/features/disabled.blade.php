@extends(auth()->check() ? 'layouts.app' : 'layouts.public')

@section('title', 'Fitur dinonaktifkan')

@section('content')
    <h1>Fitur dinonaktifkan</h1>
    <p>Fitur "{{ $feature->label() }}" sedang dinonaktifkan oleh administrator.</p>
    @auth
        @can('manage-fleet')
            <p><a href="{{ route('features.index') }}">Buka pengaturan fitur</a></p>
        @endcan
        <p><a href="{{ route('dashboard') }}">Kembali ke dashboard</a></p>
    @endauth
@endsection
