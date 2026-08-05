@extends('serversplitter::client.layout')

@section('title', 'ServerSplitter - ' . $server->name)

@section('content')
    @php($distribute = $state['mode'] === 'distribute')

    <h1 class="ss-title">{{ $server->name }}</h1>
    <p class="ss-muted">
        Divisiones usadas: <strong>{{ $state['splits_used'] }}</strong> de
        <strong>{{ $state['splits_unlimited'] ? 'ilimitadas' : $state['splits_max'] }}</strong>
        &middot; Modo: <strong>{{ $distribute ? 'reparto equitativo' : 'resta de la diferencia' }}</strong>
    </p>

    @if ($state['locked'])
        <div class="ss-alert ss-alert--warn" role="status">
            Hay una operacion en curso sobre este servidor. Espera a que termine antes de aplicar mas cambios.
        </div>
    @endif

    @if ($state['is_child'])
        <div class="ss-alert ss-alert--warn" role="status">
            Este servidor ya es una division y no puede volver a dividirse.
        </div>
    @endif

    <section class="ss-card" aria-labelledby="ss-current">
        <h2 class="ss-card__title" id="ss-current">Recursos actuales del servidor padre</h2>
        <dl class="ss-stats">
            <div class="ss-stat">
                <dt>Memoria</dt>
                <dd>{{ $state['server']['memory'] === 0 ? 'ilimitada' : $state['server']['memory'] . ' MiB' }}</dd>
            </div>
            <div class="ss-stat">
                <dt>Disco</dt>
                <dd>{{ $state['server']['disk'] === 0 ? 'ilimitado' : $state['server']['disk'] . ' MiB' }}</dd>
            </div>
            <div class="ss-stat">
                <dt>CPU</dt>
                <dd>{{ $state['server']['cpu'] === 0 ? 'ilimitada' : $state['server']['cpu'] . ' %' }}</dd>
            </div>
            <div class="ss-stat">
                <dt>Total gestionado</dt>
                <dd>{{ $state['pool']['memory'] }} MiB RAM</dd>
            </div>
        </dl>
        <p class="ss-muted ss-small">
            Al aplicar cambios el servidor padre recibira la accion configurada por el administrador:
            <strong>{{ $settings['power_action'] === 'none' ? 'ninguna' : $settings['power_action'] }}</strong>.
        </p>
    </section>

    <section class="ss-card" aria-labelledby="ss-splits">
        <h2 class="ss-card__title" id="ss-splits">Divisiones existentes</h2>

        @if (count($state['splits']) === 0)
            <p class="ss-muted">Todavia no has creado ninguna division.</p>
        @else
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <caption class="ss-visually-hidden">Lista de divisiones del servidor</caption>
                    <thead>
                    <tr>
                        <th scope="col">Nombre</th>
                        <th scope="col">Memoria</th>
                        <th scope="col">Disco</th>
                        <th scope="col">CPU</th>
                        <th scope="col"><span class="ss-visually-hidden">Acciones</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($state['splits'] as $split)
                        <tr>
                            <td data-label="Nombre">{{ $split['name'] }}</td>
                            <td data-label="Memoria">{{ $split['memory'] }} MiB</td>
                            <td data-label="Disco">{{ $split['disk'] }} MiB</td>
                            <td data-label="CPU">{{ $split['cpu'] }} %</td>
                            <td data-label="Acciones" class="ss-table__actions">
                                @if ($settings['allow_child_deletion'])
                                    <form method="POST"
                                          action="{{ route('serversplitter.destroy', ['server' => $server->uuidShort, 'split' => $split['id']]) }}"
                                          data-confirm="Se eliminara el servidor «{{ $split['name'] }}» y todos sus archivos. Esta accion no se puede deshacer. Continuar?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="ss-btn ss-btn--danger ss-btn--sm">Eliminar</button>
                                    </form>
                                @else
                                    <span class="ss-muted ss-small">Contacta con soporte</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if (!$state['is_child'] && $settings['enabled'] && ($state['splits_unlimited'] || $state['splits_used'] < $state['splits_max']))
        <section class="ss-card" aria-labelledby="ss-new">
            <h2 class="ss-card__title" id="ss-new">Crear una nueva division</h2>

            @if ($eggs->isEmpty())
                <p class="ss-muted">No hay plantillas (eggs) habilitadas para dividir. Contacta con el administrador.</p>
            @else
                <form method="POST"
                      action="{{ route('serversplitter.store', ['server' => $server->uuidShort]) }}"
                      id="ss-form"
                      data-mode="{{ $state['mode'] }}"
                      data-parent-memory="{{ $state['server']['memory'] }}"
                      data-parent-disk="{{ $state['server']['disk'] }}"
                      data-parent-cpu="{{ $state['server']['cpu'] }}"
                      data-reserve-memory="{{ $settings['reserve_memory'] }}"
                      data-reserve-disk="{{ $settings['reserve_disk'] }}"
                      data-reserve-cpu="{{ $settings['reserve_cpu'] }}"
                      data-confirm="Se creara un nuevo servidor y se redimensionara el principal. Continuar?">
                    @csrf

                    <div class="ss-fields">
                        <div class="ss-field">
                            <label for="ss-name">Nombre (opcional)</label>
                            <input type="text" id="ss-name" name="name" maxlength="120"
                                   value="{{ old('name') }}" placeholder="Se genera automaticamente si lo dejas vacio">
                        </div>

                        <div class="ss-field">
                            <label for="ss-egg-id">Plantilla (egg)</label>
                            <select id="ss-egg-id" name="egg_id" required>
                                @foreach ($eggs as $egg)
                                    <option value="{{ $egg->id }}" @selected((int) old('egg_id') === $egg->id)>
                                        {{ $egg->nest?->name ? $egg->nest->name . ' / ' : '' }}{{ $egg->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        @unless ($distribute)
                            <div class="ss-field">
                                <label for="ss-memory">Memoria para la division (MiB)</label>
                                <input type="number" id="ss-memory" name="memory" data-resource="memory"
                                       min="{{ $settings['min_memory'] }}" step="128" required
                                       value="{{ old('memory', $settings['min_memory']) }}">
                                <p class="ss-hint">Minimo {{ $settings['min_memory'] }} MiB.</p>
                            </div>

                            <div class="ss-field">
                                <label for="ss-disk">Disco para la division (MiB)</label>
                                <input type="number" id="ss-disk" name="disk" data-resource="disk"
                                       min="{{ $settings['min_disk'] }}" step="256"
                                       value="{{ old('disk', $settings['min_disk']) }}"
                                       @disabled(!$settings['allow_disk'])>
                                <p class="ss-hint">
                                    @if ($settings['allow_disk'])
                                        Minimo {{ $settings['min_disk'] }} MiB.
                                    @else
                                        El administrador fija el disco en {{ $settings['min_disk'] }} MiB.
                                    @endif
                                </p>
                            </div>

                            <div class="ss-field">
                                <label for="ss-cpu">CPU para la division (%)</label>
                                <input type="number" id="ss-cpu" name="cpu" data-resource="cpu"
                                       min="{{ $settings['min_cpu'] }}" step="5"
                                       value="{{ old('cpu', $settings['min_cpu']) }}"
                                       @disabled(!$settings['allow_cpu'])>
                                <p class="ss-hint">
                                    @if ($settings['allow_cpu'])
                                        Minimo {{ $settings['min_cpu'] }} %.
                                    @else
                                        El administrador fija la CPU en {{ $settings['min_cpu'] }} %.
                                    @endif
                                </p>
                            </div>
                        @else
                            <div class="ss-field ss-field--full">
                                <p class="ss-alert ss-alert--info">
                                    El administrador ha activado el <strong>reparto equitativo</strong>: los recursos
                                    totales se dividiran en partes iguales entre el servidor principal y sus
                                    {{ $state['splits_used'] + 1 }} divisiones. No hace falta indicar cantidades.
                                </p>
                            </div>
                        @endunless
                    </div>

                    <p class="ss-preview" id="ss-preview" aria-live="polite"></p>

                    <button type="submit" class="ss-btn ss-btn--primary">Crear division</button>
                </form>
            @endif
        </section>
    @elseif (!$state['is_child'] && !$state['splits_unlimited'] && $state['splits_used'] >= $state['splits_max'])
        <section class="ss-card">
            <p class="ss-muted">
                Has alcanzado el maximo de divisiones ({{ $state['splits_max'] }}). Amplia tu plan o elimina una
                division existente para crear otra.
            </p>
        </section>
    @endif
@endsection