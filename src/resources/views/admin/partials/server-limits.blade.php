{{--
    ServerSplitter - limites de division de un servidor concreto.

    Este partial se inyecta en las vistas de administracion del panel
    (resources/views/admin/servers/new.blade.php y
    resources/views/admin/servers/view/build.blade.php) mediante
    tools/patch-panel.php, dentro del formulario que ya existe.

    Variable esperada:
      $ssServer  Server|null  El servidor que se esta editando, o null al crear.

    Los valores los guarda AdminServerLimits (middleware de la extension) a
    partir del array serversplitter[...] que se envia con el formulario.
--}}
@php
    $ssLimit = null;

    if (isset($ssServer) && $ssServer !== null) {
        $ssLimit = \Pterodactyl\Extensions\ServerSplitter\Models\SplitterLimit::query()
            ->where('server_id', $ssServer->id)
            ->first();
    }

    $ssFields = [
        'max_splits' => [
            'label' => 'Divisiones maximas',
            'help' => 'Vacio = valor global; 0 = ilimitado.',
        ],
        'max_memory' => [
            'label' => 'Memoria total (MiB)',
            'help' => 'Suma maxima repartida entre las divisiones. 0 = ilimitado.',
        ],
        'max_disk' => [
            'label' => 'Disco total (MiB)',
            'help' => 'Vacio = valor global; 0 = ilimitado.',
        ],
        'max_cpu' => [
            'label' => 'CPU total (%)',
            'help' => 'Vacio = valor global; 0 = ilimitado.',
        ],
    ];

    // Tri-estado: '' = usar el comportamiento global (permitido), '1' = si
    // puede elegir egg, '0' = no puede (la division hereda el egg del padre).
    $ssAllowEggChoice = old('serversplitter.allow_egg_choice');

    if ($ssAllowEggChoice === null) {
        $ssAllowEggChoice = $ssLimit?->allow_egg_choice === null
            ? ''
            : ($ssLimit->allow_egg_choice ? '1' : '0');
    }
@endphp

<div class="box-body" style="border-top:1px solid #f4f4f4;">
    <h4 style="margin-top:0;">
        ServerSplitter <small>limites de division de este servidor</small>
    </h4>
    <div class="row">
        @foreach ($ssFields as $ssKey => $ssField)
            <div class="form-group col-xs-12 col-sm-6 col-md-3">
                <label class="control-label" for="ss-server-{{ $ssKey }}">{{ $ssField['label'] }}</label>
                <input class="form-control" id="ss-server-{{ $ssKey }}" type="number" min="0" step="1"
                       name="serversplitter[{{ $ssKey }}]"
                       value="{{ old('serversplitter.' . $ssKey, $ssLimit?->{$ssKey}) }}"
                       aria-describedby="ss-server-{{ $ssKey }}-help">
                <p class="text-muted small" id="ss-server-{{ $ssKey }}-help">{{ $ssField['help'] }}</p>
            </div>
        @endforeach

        <div class="form-group col-xs-12 col-sm-6 col-md-3">
            <label class="control-label" for="ss-server-allow-egg-choice">Eleccion de egg</label>
            <select class="form-control" id="ss-server-allow-egg-choice" name="serversplitter[allow_egg_choice]"
                    aria-describedby="ss-server-allow-egg-choice-help">
                <option value="" @selected($ssAllowEggChoice === '')>Usar valor global (permitido)</option>
                <option value="1" @selected($ssAllowEggChoice === '1')>Si, puede elegir egg</option>
                <option value="0" @selected($ssAllowEggChoice === '0')>No: hereda el egg del padre</option>
            </select>
            <p class="text-muted small" id="ss-server-allow-egg-choice-help">
                Si eliges "No", cada division que cree el propietario usara siempre el mismo egg que
                este servidor, sin selector.
            </p>
        </div>
    </div>
    <p class="text-muted small" style="margin-bottom:0;">
        Deja los campos numericos vacios para que este servidor use unicamente la configuracion global de
        ServerSplitter. Estos mismos limites se pueden establecer tambien por API.
    </p>
</div>