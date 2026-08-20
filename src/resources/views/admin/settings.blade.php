@extends('layouts.admin')

@section('title')
    ServerSplitter
@endsection

@section('content-header')
    <h1>ServerSplitter <small>division segura de servidores</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">ServerSplitter</li>
    </ol>
@endsection

@section('content')
    @include('serversplitter::admin.partials.grid-fix')
    <style>
        /* El panel de administracion de Pterodactyl (AdminLTE) fuerza cajas blancas.
           Aqui se neutraliza ese blanco: los contenedores pasan a ser transparentes
           (heredan el fondo real del tema activo) y los bordes/rellenos usan grises
           translucidos, de modo que la pagina funciona igual en tema claro y oscuro
           sin fijar ningun color de fondo propio. */
        .ss-admin,
        .ss-admin .box-title,
        .ss-admin .box-body,
        .ss-admin .box-footer,
        .ss-admin label,
        .ss-admin h4 {
            color: inherit;
        }

        .ss-admin .nav-tabs-custom,
        .ss-admin .nav-tabs-custom > .tab-content,
        .ss-admin .box,
        .ss-admin .box-header,
        .ss-admin .box-body,
        .ss-admin .box-footer {
            background-color: transparent;
            box-shadow: none;
        }

        .ss-admin .box {
            border: 1px solid rgba(127, 127, 127, 0.28);
            border-top-width: 3px;
            border-radius: 4px;
        }

        .ss-admin .box.box-primary {
            border-top-color: #3c8dbc;
        }

        .ss-admin .box.box-warning {
            border-top-color: #f39c12;
        }

        .ss-admin .box.box-default {
            border-top-color: rgba(127, 127, 127, 0.45);
        }

        .ss-admin .box-header.with-border,
        .ss-admin .box-footer {
            border-color: rgba(127, 127, 127, 0.25);
        }

        .ss-admin hr {
            border-top-color: rgba(127, 127, 127, 0.25);
        }

        .ss-admin .text-muted {
            color: inherit;
            opacity: 0.72;
        }

        .ss-admin code {
            background-color: rgba(127, 127, 127, 0.18);
            color: inherit;
        }

        /* Campos y tablas: AdminLTE los pinta en blanco fijo. Se dejan
           translucidos para que funcionen sobre fondo claro y oscuro. */
        .ss-admin .form-control,
        .ss-admin .input-group-addon,
        .ss-admin select.form-control {
            background-color: rgba(127, 127, 127, 0.12);
            border-color: rgba(127, 127, 127, 0.32);
            color: inherit;
        }

        .ss-admin .form-control:focus {
            border-color: rgba(60, 141, 188, 0.75);
            box-shadow: none;
        }

        .ss-admin .form-control::placeholder {
            color: inherit;
            opacity: 0.55;
        }

        .ss-admin .table,
        .ss-admin .table > thead > tr > th,
        .ss-admin .table > tbody > tr > td {
            background-color: transparent;
            border-color: rgba(127, 127, 127, 0.25);
            color: inherit;
        }

        .ss-admin .table-hover > tbody > tr:hover > td {
            background-color: rgba(127, 127, 127, 0.12);
        }

        /* Desbordamiento en moviles: las tablas anchas hacen scroll en vez de
           romper el layout del panel. */
        .ss-admin .table-responsive {
            border: 0;
        }

        @media (max-width: 640px) {
            .ss-admin .nav-tabs-custom > .nav-tabs > li {
                float: none;
                display: block;
            }

            .ss-admin .box-footer .btn {
                width: 100%;
                margin-bottom: 8px;
            }
        }

        /* ---- Pestanas ---- */

        .ss-admin .nav-tabs-custom > .nav-tabs {
            border-bottom-color: rgba(127, 127, 127, 0.28);
        }

        .ss-admin .nav-tabs-custom > .nav-tabs > li > a,
        .ss-admin .nav-tabs-custom > .nav-tabs > li.active > a {
            background-color: transparent;
            color: inherit;
            border-left-color: rgba(127, 127, 127, 0.2);
            border-right-color: rgba(127, 127, 127, 0.2);
        }

        .ss-admin .nav-tabs-custom > .nav-tabs > li:not(.active) > a {
            opacity: 0.72;
        }

        .ss-admin .nav-tabs-custom > .nav-tabs > li:not(.active) > a:hover,
        .ss-admin .nav-tabs-custom > .nav-tabs > li:not(.active) > a:focus {
            background-color: rgba(127, 127, 127, 0.1);
            opacity: 1;
        }

        .ss-admin .nav-tabs-custom > .nav-tabs > li.active > a {
            background-color: rgba(127, 127, 127, 0.14);
        }

        /* ---- Formularios ---- */

        .ss-admin .form-control {
            background-color: rgba(127, 127, 127, 0.14);
            border-color: rgba(127, 127, 127, 0.35);
            color: inherit;
            box-shadow: none;
        }

        .ss-admin .form-control:focus {
            border-color: #3c8dbc;
            box-shadow: none;
        }

        .ss-admin .form-control::placeholder {
            color: inherit;
            opacity: 0.55;
        }

        .ss-admin a:focus-visible,
        .ss-admin button:focus-visible,
        .ss-admin input:focus-visible,
        .ss-admin select:focus-visible {
            outline: 2px solid #7cc0ff;
            outline-offset: 2px;
        }

        /* ---- Tablas ---- */

        .ss-admin .table > thead > tr > th,
        .ss-admin .table > tbody > tr > td {
            border-color: rgba(127, 127, 127, 0.25);
            color: inherit;
        }

        .ss-admin .table-hover > tbody > tr:hover {
            background-color: rgba(127, 127, 127, 0.12);
        }

        @media (max-width: 640px) {
            .ss-admin .box-footer .btn.pull-right,
            .ss-admin .box-footer > .btn {
                float: none !important;
                width: 100%;
            }
        }
    </style>

    <div class="ss-admin">
    @if (session('serversplitter:success'))
        <div class="alert alert-success">{{ session('serversplitter:success') }}</div>
    @endif
    @if (session('serversplitter:error'))
        <div class="alert alert-danger">{{ session('serversplitter:error') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul style="margin:0; padding-left:18px;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @unless ($hasApiKey)
        <div class="alert alert-warning">
            La API de integracion esta cerrada. Genera una clave en la consola con
            <code>serversplitter apikey</code> para poder usarla.
        </div>
    @endunless

    <div class="nav-tabs-custom">
        <ul class="nav nav-tabs" id="ss-tabs" role="tablist">
            <li class="active">
                <a href="#ss-tab-general" data-toggle="tab" role="tab" aria-controls="ss-tab-general" aria-selected="true">
                    <i class="fa fa-cogs"></i> General
                </a>
            </li>
            <li>
                <a href="#ss-tab-eggs" data-toggle="tab" role="tab" aria-controls="ss-tab-eggs" aria-selected="false">
                    <i class="fa fa-flask"></i> Reglas de eggs <span class="badge">{{ $rules->count() }}</span>
                </a>
            </li>
            <li>
                <a href="#ss-tab-splits" data-toggle="tab" role="tab" aria-controls="ss-tab-splits" aria-selected="false">
                    <i class="fa fa-sitemap"></i> Divisiones <span class="badge">{{ $totalSplits }}</span>
                </a>
            </li>
            <li>
                <a href="#ss-tab-tools" data-toggle="tab" role="tab" aria-controls="ss-tab-tools" aria-selected="false">
                    <i class="fa fa-wrench"></i> Mantenimiento
                </a>
            </li>
        </ul>

        <div class="tab-content">
            {{-- ------------------------------------------------------------------
                 Pestana 1: configuracion global + resumen
            ------------------------------------------------------------------ --}}
            <div class="tab-pane active" id="ss-tab-general" role="tabpanel">
                <div class="row">
                    <div class="col-xs-12 col-md-8">
                        <form action="{{ route('admin.serversplitter.settings.update') }}" method="POST">
                            @csrf
                            @method('PATCH')
                            <div class="box box-primary">
                                <div class="box-header with-border">
                                    <h3 class="box-title">Configuracion general</h3>
                                </div>
                                <div class="box-body">
                                    <div class="row">
                                        <div class="form-group col-xs-12 col-sm-6">
                                            <label class="control-label" for="ss-enabled">Sistema activo</label>
                                            <select class="form-control" id="ss-enabled" name="enabled">
                                                <option value="1" @selected($settings['enabled'])>Si</option>
                                                <option value="0" @selected(!$settings['enabled'])>No</option>
                                            </select>
                                        </div>
                                        <div class="form-group col-xs-12 col-sm-6">
                                            <label class="control-label" for="ss-max-splits">Divisiones maximas por servidor</label>
                                            <input class="form-control" id="ss-max-splits" type="number" min="0" max="100"
                                                   name="max_splits" value="{{ $settings['max_splits'] }}" required
                                                   aria-describedby="ss-max-splits-help">
                                            <p class="text-muted small" id="ss-max-splits-help">
                                                Usa <code>0</code> para no aplicar ningun tope (divisiones ilimitadas).
                                                Cada servidor puede sobrescribir este valor desde su propia pestana
                                                <em>Build Configuration</em>.
                                            </p>
                                        </div>
                                        <div class="form-group col-xs-12 col-sm-6">
                                            <label class="control-label" for="ss-mode">Redimensionado del padre</label>
                                            <select class="form-control" id="ss-mode" name="resize_mode">
                                                <option value="difference" @selected($settings['resize_mode'] === 'difference')>
                                                    Restar / sumar la diferencia
                                                </option>
                                                <option value="distribute" @selected($settings['resize_mode'] === 'distribute')>
                                                    Reparto equitativo entre padre e hijos
                                                </option>
                                            </select>
                                            <p class="text-muted small">
                                                En modo equitativo se ignoran los valores que pida el usuario: el total se divide
                                                en partes iguales entre el padre y todas sus divisiones.
                                            </p>
                                        </div>
                                        <div class="form-group col-xs-12 col-sm-6">
                                            <label class="control-label" for="ss-power">Accion al aplicar cambios</label>
                                            <select class="form-control" id="ss-power" name="power_action">
                                                <option value="none" @selected($settings['power_action'] === 'none')>No hacer nada</option>
                                                <option value="restart" @selected($settings['power_action'] === 'restart')>Reiniciar el padre</option>
                                                <option value="stop" @selected($settings['power_action'] === 'stop')>Detener el padre</option>
                                                <option value="kill" @selected($settings['power_action'] === 'kill')>Apagar (kill) el padre</option>
                                            </select>
                                        </div>
                                    </div>

                                    <hr>
                                    <h4 style="margin-top:0;">Minimos y reservas</h4>
                                    <div class="row">
                                        <div class="form-group col-xs-12 col-sm-4">
                                            <label class="control-label" for="ss-min-memory">Memoria minima (MiB)</label>
                                            <input class="form-control" id="ss-min-memory" type="number" min="0" name="min_memory"
                                                   value="{{ $settings['min_memory'] }}" required>
                                        </div>
                                        <div class="form-group col-xs-12 col-sm-4">
                                            <label class="control-label" for="ss-min-disk">Disco minimo (MiB)</label>
                                            <input class="form-control" id="ss-min-disk" type="number" min="0" name="min_disk"
                                                   value="{{ $settings['min_disk'] }}" required>
                                        </div>
                                        <div class="form-group col-xs-12 col-sm-4">
                                            <label class="control-label" for="ss-min-cpu">CPU minima (%)</label>
                                            <input class="form-control" id="ss-min-cpu" type="number" min="0" name="min_cpu"
                                                   value="{{ $settings['min_cpu'] }}" required>
                                        </div>
                                        <div class="form-group col-xs-12 col-sm-4">
                                            <label class="control-label" for="ss-res-memory">Memoria reservada al padre</label>
                                            <input class="form-control" id="ss-res-memory" type="number" min="0" name="reserve_memory"
                                                   value="{{ $settings['reserve_memory'] }}" required>
                                        </div>
                                        <div class="form-group col-xs-12 col-sm-4">
                                            <label class="control-label" for="ss-res-disk">Disco reservado al padre</label>
                                            <input class="form-control" id="ss-res-disk" type="number" min="0" name="reserve_disk"
                                                   value="{{ $settings['reserve_disk'] }}" required>
                                        </div>
                                        <div class="form-group col-xs-12 col-sm-4">
                                            <label class="control-label" for="ss-res-cpu">CPU reservada al padre (%)</label>
                                            <input class="form-control" id="ss-res-cpu" type="number" min="0" name="reserve_cpu"
                                                   value="{{ $settings['reserve_cpu'] }}" required>
                                        </div>
                                    </div>

                                    <hr>
                                    <h4 style="margin-top:0;">Comportamiento de las divisiones</h4>
                                    <div class="row">
                                        <div class="form-group col-xs-12 col-sm-6">
                                            <label class="control-label" for="ss-prefix">Prefijo de nombre de las divisiones</label>
                                            <input class="form-control" id="ss-prefix" type="text" maxlength="32"
                                                   name="child_name_prefix" value="{{ $settings['child_name_prefix'] }}" required>
                                        </div>
                                        <div class="col-xs-12 col-sm-6">
                                            <div class="checkbox">
                                                <label>
                                                    <input type="checkbox" name="allow_unlisted_eggs" value="1" @checked($settings['allow_unlisted_eggs'])>
                                                    Permitir eggs sin regla definida
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="box-footer">
                                    <button type="submit" class="btn btn-primary pull-right">Guardar configuracion</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="col-xs-12 col-md-4">
                        <div class="box box-default">
                            <div class="box-header with-border"><h3 class="box-title">Resumen</h3></div>
                            <div class="box-body">
                                <p><strong>Estado:</strong>
                                    @if ($settings['enabled'])
                                        <span class="label label-success">activo</span>
                                    @else
                                        <span class="label label-default">desactivado</span>
                                    @endif
                                </p>
                                <p><strong>Divisiones activas:</strong> {{ $totalSplits }}</p>
                                <p><strong>Reglas de eggs:</strong> {{ $rules->count() }}</p>
                                <p><strong>Servidores con limites propios:</strong> {{ $limitedServers }}</p>
                                <p><strong>Panel de cliente:</strong> <code>/serversplitter</code></p>
                                <p style="margin-bottom:0;">
                                    <strong>API de integracion:</strong>
                                    <code>/api/serversplitter/servers/&#123;server&#125;</code>
                                </p>
                            </div>
                        </div>

                        <div class="box box-default">
                            <div class="box-header with-border"><h3 class="box-title">Limites de un servidor</h3></div>
                            <div class="box-body">
                                <p class="text-muted" style="margin-bottom:0;">
                                    Los limites de cada servidor (divisiones, memoria, disco y CPU) se editan
                                    directamente en el propio servidor, dentro de
                                    <strong>Servidores &rarr; ver servidor &rarr; Build Configuration</strong>,
                                    y tambien al crearlo. Deja los campos vacios para usar la configuracion global
                                    de esta pagina y pon <code>0</code> para ilimitado.
                                </p>
                            </div>
                            <div class="box-footer">
                                <a class="btn btn-default btn-sm" href="{{ route('admin.servers') }}">
                                    Ir a la lista de servidores
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ------------------------------------------------------------------
                 Pestana 2: reglas por egg
            ------------------------------------------------------------------ --}}
            <div class="tab-pane" id="ss-tab-eggs" role="tabpanel">
                <div class="row">
                    <div class="col-xs-12 col-md-5">
                        <div class="box box-primary">
                            <div class="box-header with-border"><h3 class="box-title">Nueva regla / editar existente</h3></div>
                            <form action="{{ route('admin.serversplitter.eggs.store') }}" method="POST">
                                @csrf
                                <div class="box-body">
                                    <div class="form-group">
                                        <label class="control-label" for="ss-egg">Egg</label>
                                        <select class="form-control" id="ss-egg" name="egg_id" required>
                                            @foreach ($eggs as $egg)
                                                <option value="{{ $egg->id }}">
                                                    {{ $egg->nest?->name ? $egg->nest->name . ' / ' : '' }}{{ $egg->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="checkbox">
                                        <label><input type="checkbox" name="allowed" value="1" checked> Permitido en divisiones</label>
                                    </div>
                                    <div class="row">
                                        <div class="form-group col-xs-6 col-sm-4">
                                            <label class="control-label" for="ss-egg-min-memory">RAM min.</label>
                                            <input class="form-control" id="ss-egg-min-memory" type="number" min="0" name="min_memory">
                                        </div>
                                        <div class="form-group col-xs-6 col-sm-4">
                                            <label class="control-label" for="ss-egg-min-disk">Disco min.</label>
                                            <input class="form-control" id="ss-egg-min-disk" type="number" min="0" name="min_disk">
                                        </div>
                                        <div class="form-group col-xs-6 col-sm-4">
                                            <label class="control-label" for="ss-egg-min-cpu">CPU min.</label>
                                            <input class="form-control" id="ss-egg-min-cpu" type="number" min="0" name="min_cpu">
                                        </div>
                                        <div class="form-group col-xs-6 col-sm-4">
                                            <label class="control-label" for="ss-egg-max-memory">RAM max.</label>
                                            <input class="form-control" id="ss-egg-max-memory" type="number" min="0" name="max_memory">
                                        </div>
                                        <div class="form-group col-xs-6 col-sm-4">
                                            <label class="control-label" for="ss-egg-max-disk">Disco max.</label>
                                            <input class="form-control" id="ss-egg-max-disk" type="number" min="0" name="max_disk">
                                        </div>
                                        <div class="form-group col-xs-6 col-sm-4">
                                            <label class="control-label" for="ss-egg-max-cpu">CPU max.</label>
                                            <input class="form-control" id="ss-egg-max-cpu" type="number" min="0" name="max_cpu">
                                        </div>
                                    </div>
                                    <p class="text-muted small" style="margin-bottom:0;">
                                        Deja un campo vacio para usar el valor global. Si el egg ya tiene una regla,
                                        se sobrescribe.
                                    </p>
                                </div>
                                <div class="box-footer">
                                    <button type="submit" class="btn btn-primary btn-sm pull-right">Guardar regla</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="col-xs-12 col-md-7">
                        <div class="box box-default">
                            <div class="box-header with-border"><h3 class="box-title">Reglas definidas</h3></div>
                            <div class="box-body table-responsive no-padding">
                                <table class="table table-hover">
                                    <thead>
                                    <tr>
                                        <th>Egg</th>
                                        <th>Estado</th>
                                        <th>Min (RAM/Disco/CPU)</th>
                                        <th>Max (RAM/Disco/CPU)</th>
                                        <th></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @forelse ($rules as $rule)
                                        <tr>
                                            <td>{{ $rule->egg?->name ?? ('#' . $rule->egg_id) }}</td>
                                            <td>
                                                @if ($rule->allowed)
                                                    <span class="label label-success">permitido</span>
                                                @else
                                                    <span class="label label-danger">bloqueado</span>
                                                @endif
                                            </td>
                                            <td>{{ $rule->min_memory ?: '-' }} / {{ $rule->min_disk ?: '-' }} / {{ $rule->min_cpu ?: '-' }}</td>
                                            <td>{{ $rule->max_memory ?: '-' }} / {{ $rule->max_disk ?: '-' }} / {{ $rule->max_cpu ?: '-' }}</td>
                                            <td class="text-right">
                                                <form action="{{ route('admin.serversplitter.eggs.destroy', $rule->id) }}" method="POST">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-xs btn-danger">Quitar</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">
                                                Todavia no hay reglas por egg.
                                            </td>
                                        </tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ------------------------------------------------------------------
                 Pestana 3: divisiones creadas
            ------------------------------------------------------------------ --}}
            <div class="tab-pane" id="ss-tab-splits" role="tabpanel">
                <div class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title">Ultimas divisiones</h3>
                        <div class="box-tools">
                            <span class="label label-default">{{ $totalSplits }} en total</span>
                        </div>
                    </div>
                    <div class="box-body table-responsive no-padding">
                        <table class="table table-hover">
                            <thead>
                            <tr>
                                <th>Hijo</th>
                                <th>Padre</th>
                                <th>Propietario</th>
                                <th>RAM</th>
                                <th>Disco</th>
                                <th>CPU</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($splits as $split)
                                <tr>
                                    <td>
                                        @if ($split->server)
                                            <a href="{{ route('admin.servers.view', $split->server->id) }}">{{ $split->server->name }}</a>
                                        @else
                                            <em>eliminado</em>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($split->parent)
                                            <a href="{{ route('admin.servers.view', $split->parent->id) }}">{{ $split->parent->name }}</a>
                                        @else
                                            <em>eliminado</em>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($split->parent && $split->parent->owner_id)
                                            <a href="{{ route('admin.users.view', $split->parent->owner_id) }}">
                                                #{{ $split->parent->owner_id }}
                                            </a>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td>{{ $split->server->memory ?? $split->memory }}</td>
                                    <td>{{ $split->server->disk ?? $split->disk }}</td>
                                    <td>{{ $split->server->cpu ?? $split->cpu }}</td>
                                    <td class="text-right">
                                        <form action="{{ route('admin.serversplitter.splits.destroy', $split->id) }}" method="POST"
                                              onsubmit="return confirm('Esto ELIMINA el servidor hijo y devuelve los recursos al padre. Continuar?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-xs btn-danger">Eliminar</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted">Todavia no hay divisiones.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="box-footer text-muted small">
                        Se muestran las 50 divisiones mas recientes. Eliminar una division borra el servidor hijo
                        y devuelve sus recursos al padre.
                    </div>
                </div>
            </div>

            {{-- ------------------------------------------------------------------
                 Pestana 4: mantenimiento
            ------------------------------------------------------------------ --}}
            <div class="tab-pane" id="ss-tab-tools" role="tabpanel">
                <div class="row">
                    <div class="col-xs-12 col-md-6">
                        <div class="box box-default">
                            <div class="box-header with-border"><h3 class="box-title">Cache y bloqueos</h3></div>
                            <div class="box-body">
                                <p class="text-muted" style="margin-bottom:0;">
                                    Vacia la cache de configuracion de la extension y borra los bloqueos que ya han
                                    caducado. Es seguro ejecutarlo en cualquier momento.
                                </p>
                            </div>
                            <div class="box-footer">
                                <form action="{{ route('admin.serversplitter.maintenance') }}" method="POST">
                                    @csrf
                                    <button type="submit" class="btn btn-default btn-sm">
                                        Limpiar cache y bloqueos caducados
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-xs-12 col-md-6">
                        <div class="box box-warning">
                            <div class="box-header with-border"><h3 class="box-title">Liberar bloqueo de un servidor</h3></div>
                            <form action="{{ route('admin.serversplitter.unlock') }}" method="POST">
                                @csrf
                                <div class="box-body">
                                    <div class="form-group">
                                        <label class="control-label" for="ss-unlock-server">ID interno del servidor</label>
                                        <input class="form-control" id="ss-unlock-server" type="number" min="1"
                                               name="server_id" required aria-describedby="ss-unlock-help">
                                        <p class="text-muted small" id="ss-unlock-help">
                                            Usalo solo si una operacion se quedo a medias y el servidor sigue
                                            marcado como ocupado.
                                        </p>
                                    </div>
                                </div>
                                <div class="box-footer">
                                    <button type="submit" class="btn btn-warning btn-sm">Liberar bloqueo</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>
@endsection

@section('footer-scripts')
    @parent
    <script>
        (function () {
            'use strict';

            if (typeof window.jQuery === 'undefined') {
                return;
            }

            var $ = window.jQuery;
            var allowed = ['#ss-tab-general', '#ss-tab-eggs', '#ss-tab-splits', '#ss-tab-tools'];

            // Reabre la pestana indicada en la URL (los controladores redirigen con #ss-tab-...).
            var hash = window.location.hash;

            if (allowed.indexOf(hash) !== -1) {
                var $link = $('#ss-tabs a[href="' + hash + '"]');

                if ($link.length > 0) {
                    $link.tab('show');
                }
            }

            // Mantiene la URL sincronizada sin ensuciar el historial.
            $('#ss-tabs a[data-toggle="tab"]').on('shown.bs.tab', function (event) {
                var target = event.target.getAttribute('href');

                if (!target) {
                    return;
                }

                if (window.history && typeof window.history.replaceState === 'function') {
                    window.history.replaceState(null, '', window.location.pathname + window.location.search + target);
                } else {
                    window.location.hash = target;
                }
            });
        })();
    </script>
@endsection