<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'ServerSplitter')</title>
    <link rel="stylesheet" href="{{ url('extensions/serversplitter/serversplitter.css') }}">
</head>
<body class="ss-body">
<a class="ss-skip" href="#ss-main">Saltar al contenido</a>

<header class="ss-header">
    <div class="ss-container ss-header__inner">
        <span class="ss-brand">
            <span class="ss-brand__mark" aria-hidden="true">&#9707;</span>
            ServerSplitter
        </span>
        <nav class="ss-nav" aria-label="Navegacion principal">
            <a class="ss-nav__link" href="{{ url('/') }}">Volver al panel</a>
        </nav>
    </div>
</header>

<main class="ss-container" id="ss-main">
    @if (session('serversplitter:success'))
        <div class="ss-alert ss-alert--ok" role="status">{{ session('serversplitter:success') }}</div>
    @endif
    @if (session('serversplitter:error'))
        <div class="ss-alert ss-alert--bad" role="alert">{{ session('serversplitter:error') }}</div>
    @endif
    @if ($errors->any())
        <div class="ss-alert ss-alert--bad" role="alert">
            <ul class="ss-alert__list">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

<footer class="ss-footer">
    <div class="ss-container">ServerSplitter para Pterodactyl</div>
</footer>

<script src="{{ url('extensions/serversplitter/serversplitter.js') }}" defer></script>
</body>
</html>