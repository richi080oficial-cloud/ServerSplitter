@extends('serversplitter::client.layout')

@section('title', 'ServerSplitter - Mis servidores')

@section('content')
    <h1 class="ss-title">Servidores que puedes dividir</h1>

    @unless ($settings['enabled'])
        <div class="ss-alert ss-alert--warn" role="status">
            El administrador ha desactivado temporalmente las divisiones.
        </div>
    @endunless

    @if ($servers->isEmpty())
        <div class="ss-card">
            <p class="ss-muted">No tienes servidores disponibles para dividir.</p>
        </div>
    @else
        <div class="ss-grid">
            @foreach ($servers as $server)
                @php($state = $splitter->state($server))
                <article class="ss-card">
                    <h2 class="ss-card__title">{{ $server->name }}</h2>
                    <dl class="ss-stats">
                        <div class="ss-stat">
                            <dt>Memoria</dt>
                            <dd>{{ $server->memory === 0 ? 'ilimitada' : $server->memory . ' MiB' }}</dd>
                        </div>
                        <div class="ss-stat">
                            <dt>Disco</dt>
                            <dd>{{ $server->disk === 0 ? 'ilimitado' : $server->disk . ' MiB' }}</dd>
                        </div>
                        <div class="ss-stat">
                            <dt>CPU</dt>
                            <dd>{{ $server->cpu === 0 ? 'ilimitada' : $server->cpu . ' %' }}</dd>
                        </div>
                        <div class="ss-stat">
                            <dt>Divisiones</dt>
                            <dd>{{ $state['splits_used'] }} / {{ $state['splits_max'] }}</dd>
                        </div>
                    </dl>
                    <a class="ss-btn ss-btn--primary" href="{{ route('serversplitter.show', $server->uuidShort) }}">
                        Gestionar divisiones
                    </a>
                </article>
            @endforeach
        </div>
    @endif
@endsection