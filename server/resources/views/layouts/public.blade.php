<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · NetWatch</title>
    <link rel="stylesheet" href="{{ asset('css/netwatch.css') }}">
</head>
<body class="public-page">
<main class="public-card">
    @if ($errors->any())
        <div class="alert alert-error" role="alert">
            @foreach ($errors->all() as $message)<div>{{ $message }}</div>@endforeach
        </div>
    @endif
    @yield('content')
</main>
</body>
</html>
