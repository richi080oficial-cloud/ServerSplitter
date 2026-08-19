{{--
    ServerSplitter - Panel de division integrado en la vista del servidor.
    
    Se inyecta directamente en la pestana "Build Configuration" del servidor
    para que el admin pueda ver y gestionar divisiones sin salir de la interfaz.
    
    Variable esperada:
      $ssServer  Server  El servidor que se esta editando
--}}
@php
    $splitterService = app('Pterodactyl\Extensions\ServerSplitter\Services\SplitterService');
    $settings = $splitterService->settings();
    
    // Obtener límites específicos del servidor
    $ssLimit = \Pterodactyl\Extensions\ServerSplitter\Models\SplitterLimit::query()
        ->where('server_id', $ssServer->id)
        ->first();
    
    // Obtener divisiones de este servidor
    $childSplits = \Pterodactyl\Extensions\ServerSplitter\Models\ServerSplit::query()
        ->where('parent_id', $ssServer->id)
        ->with('server')
        ->get();
    
    // Obtener si este servidor es una división
    $parentSplit = \Pterodactyl\Extensions\ServerSplitter\Models\ServerSplit::query()
        ->where('server_id', $ssServer->id)
        ->with('parent')
        ->first();
@endphp

@if ($settings['enabled'] ?? false)
<div class="box box-primary" style="margin-top: 20px;">
    <div class="box-header with-border">
        <h3 class="box-title">
            <i class="fa fa-sitemap"></i> ServerSplitter
        </h3>
        <div class="box-tools pull-right">
            <button type="button" class="btn btn-box-tool" data-widget="collapse">
                <i class="fa fa-minus"></i>
            </button>
        </div>
    </div>
    
    <div class="box-body">
        @if ($parentSplit)
            <div class="alert alert-info">
                <i class="fa fa-info-circle"></i>
                <strong>Este es un servidor dividido</strong> del padre
                <a href="{{ route('admin.servers.view', $parentSplit->parent->id) }}">
                    {{ $parentSplit->parent->name }}
                </a>
                
                @if (auth()->user()->root_admin)
                    <form action="{{ route('admin.serversplitter.splits.destroy', $parentSplit->id) }}" 
                          method="POST" 
                          style="display:inline;"
                          onsubmit="return confirm('Esto ELIMINA este servidor y devuelve los recursos al padre. ¿Continuar?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-xs btn-danger" style="margin-left: 10px;">
                            Eliminar división
                        </button>
                    </form>
                @endif
            </div>
        @endif
        
        <div class="row">
            <div class="col-xs-12 col-md-6">
                <h4>Límites de división de este servidor</h4>
                <table class="table table-condensed">
                    <tbody>
                        <tr>
                            <td><strong>Divisiones máximas:</strong></td>
                            <td>
                                @if ($ssLimit?->max_splits !== null)
                                    {{ $ssLimit->max_splits ?: 'Ilimitado' }}
                                @else
                                    <span class="text-muted">Global: {{ $settings['max_splits'] ?: 'Ilimitado' }}</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Memoria total:</strong></td>
                            <td>
                                @if ($ssLimit?->max_memory !== null)
                                    {{ $ssLimit->max_memory ?: 'Ilimitado' }} MiB
                                @else
                                    <span class="text-muted">Sin limite propio (usa los limites por egg / globales)</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Disco total:</strong></td>
                            <td>
                                @if ($ssLimit?->max_disk !== null)
                                    {{ $ssLimit->max_disk ?: 'Ilimitado' }} MiB
                                @else
                                    <span class="text-muted">Sin limite propio (usa los limites por egg / globales)</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td><strong>CPU total:</strong></td>
                            <td>
                                @if ($ssLimit?->max_cpu !== null)
                                    {{ $ssLimit->max_cpu ?: 'Ilimitado' }} %
                                @else
                                    <span class="text-muted">Sin limite propio (usa los limites por egg / globales)</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Eleccion de egg:</strong></td>
                            <td>
                                @php($ssEggModeNow = $ssLimit?->eggChoiceMode() ?? 'none')
                                @if ($ssEggModeNow === 'all')
                                    <span class="label label-success">Todos los permitidos</span>
                                @elseif ($ssEggModeNow === 'defined')
                                    <span class="label label-info">
                                        Concretos ({{ count($ssLimit?->allowedEggIds() ?? []) }})
                                    </span>
                                @else
                                    <span class="label label-warning">No, hereda el del padre</span>
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="col-xs-12 col-md-6">
                <h4>Divisiones activas ({{ $childSplits->count() }})</h4>
                @if ($childSplits->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-condensed table-hover">
                            <thead>
                                <tr>
                                    <th>Servidor hijo</th>
                                    <th>RAM</th>
                                    <th>Disco</th>
                                    <th>CPU</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($childSplits as $split)
                                    <tr onclick="window.location='{{ route('admin.servers.view', $split->server->id) }}'" style="cursor:pointer;">
                                        <td>
                                            <a href="{{ route('admin.servers.view', $split->server->id) }}">
                                                {{ $split->server->name }}
                                            </a>
                                        </td>
                                        <td>{{ $split->server->memory ?? $split->memory }} MiB</td>
                                        <td>{{ $split->server->disk ?? $split->disk }} MiB</td>
                                        <td>{{ $split->server->cpu ?? $split->cpu }}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-muted">Este servidor no tiene divisiones activas.</p>
                @endif
            </div>
        </div>
    </div>
    
    <div class="box-footer">
        <p class="text-muted small" style="margin: 0;">
            <i class="fa fa-info-circle"></i>
            Los límites de este servidor se editan en el formulario de arriba.
            Las divisiones se crean desde el panel de cliente o mediante API.
            <a href="{{ route('admin.serversplitter.index') }}">Ir al panel de administración de ServerSplitter</a>
        </p>
    </div>
</div>
@endif