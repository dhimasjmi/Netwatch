<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk · NetWatch</title>
    <link rel="stylesheet" href="{{ asset('css/netwatch.css') }}">
</head>
<body class="login-page">
<main class="login-card">
    <h1>NetWatch</h1>
    <p class="muted">Monitoring &amp; maintenance komputer jaringan kantor</p>

    <form method="POST" action="{{ route('login') }}" class="stack">
        @csrf
        <label>Email
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </label>
        <label>Kata sandi
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        @error('email')
            <div class="alert alert-error" role="alert">{{ $message }}</div>
        @enderror
        <button type="submit" class="btn btn-primary">Masuk</button>
    </form>
</main>
</body>
</html>
