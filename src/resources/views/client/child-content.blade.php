{{--
    ServerSplitter - contenido de la pagina de una division, sin la
    envoltura de pagina. Ver la nota en client/index-content.blade.php.

    Variables esperadas: $server, $origin, $ownsOrigin.
--}}
<h1 class="ss-title">{{ $server->name }}</h1>

<div class="ss-alert ss-alert--info" role="status">
    Este servidor es una <strong>division</strong>, por lo que no puede volver a dividirse.
</div>

<section class="ss-card" aria-labelledby="ss-origin">
    <h2 class="ss-card__title" id="ss-origin">Como deshacer esta division</h2>

    @if ($origin === null)
        <p class="ss-muted">
            El servidor principal del que salio esta division ya no existe. Contacta con el administrador
            para que la elimine desde el panel de administracion.
        </p>
    @elseif ($ownsOrigin)
        <p>
            Las divisiones solo se deshacen desde el servidor principal
            <strong>{{ $origin->name }}</strong> o desde el panel de administracion. Al eliminarla, sus
            recursos vuelven al servidor principal.
        </p>
        <a class="ss-btn ss-btn--primary" href="{{ route('serversplitter.show', $origin->uuidShort) }}">
            Ir a {{ $origin->name }}
        </a>
    @else
        <p class="ss-muted">
            Esta division pertenece al servidor principal <strong>{{ $origin->name }}</strong>, del que no
            eres propietario. Solo su propietario o un administrador pueden deshacerla.
        </p>
    @endif
</section>
