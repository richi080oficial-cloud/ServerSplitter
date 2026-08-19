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

    // Modo de eleccion de egg: 'none' (predeterminado) => la division hereda
    // el egg del padre; 'all' => eggs permitidos globalmente; 'defined' =>
    // solo los eggs marcados abajo para este servidor.
    $ssEggMode = old(
        'serversplitter.egg_choice_mode',
        $ssLimit?->eggChoiceMode() ?? \Pterodactyl\Extensions\ServerSplitter\Models\SplitterLimit::EGG_MODE_NONE
    );

    $ssAllowedEggIds = old('serversplitter.allowed_egg_ids', $ssLimit?->allowedEggIds() ?? []);
    $ssAllowedEggIds = array_map('intval', (array) $ssAllowedEggIds);

    $ssAllEggs = \Pterodactyl\Models\Egg::query()->with('nest')->orderBy('name')->get();
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
    </div>

    <hr>
    <h4 style="margin-top:0;">Eleccion de egg para las divisiones</h4>
    <div class="row">
        <div class="form-group col-xs-12 col-sm-4">
            <label class="control-label" for="ss-server-egg-mode">Que egg puede elegir el propietario</label>
            <select class="form-control ss-egg-mode" id="ss-server-egg-mode" name="serversplitter[egg_choice_mode]"
                    aria-describedby="ss-server-egg-mode-help">
                <option value="none" @selected($ssEggMode === 'none')>
                    No seleccionar: hereda el egg del padre (predeterminado)
                </option>
                <option value="all" @selected($ssEggMode === 'all')>
                    Todos los eggs permitidos globalmente
                </option>
                <option value="defined" @selected($ssEggMode === 'defined')>
                    Solo eggs concretos (elegir abajo)
                </option>
            </select>
            <p class="text-muted small" id="ss-server-egg-mode-help">
                "No seleccionar" es el comportamiento por defecto: el propietario no ve ningun selector y
                cada division usa siempre el mismo egg que este servidor.
            </p>
        </div>

        <div class="form-group col-xs-12 col-sm-8 ss-egg-defined-list"
             style="{{ $ssEggMode === 'defined' ? '' : 'display:none;' }}">
            <label class="control-label" for="ss-server-allowed-eggs">
                Eggs que puede elegir el propietario de este servidor
            </label>
            <select class="form-control" id="ss-server-allowed-eggs" name="serversplitter[allowed_egg_ids][]"
                    multiple size="6" aria-describedby="ss-server-allowed-eggs-help">
                @foreach ($ssAllEggs as $ssEgg)
                    <option value="{{ $ssEgg->id }}" @selected(in_array($ssEgg->id, $ssAllowedEggIds, true))>
                        {{ $ssEgg->nest?->name ? $ssEgg->nest->name . ' / ' : '' }}{{ $ssEgg->name }}
                    </option>
                @endforeach
            </select>
            <p class="text-muted small" id="ss-server-allowed-eggs-help">
                Manten pulsado Ctrl (Cmd en Mac) para elegir varios. Solo se aplica si arriba eliges
                "Solo eggs concretos".
            </p>
        </div>
    </div>

    <p class="text-muted small" style="margin-bottom:0;">
        Deja los campos numericos vacios para que este servidor use unicamente la configuracion global de
        ServerSplitter. Estos mismos limites se pueden establecer tambien por API.
    </p>
</div>

<script>
    (function () {
        'use strict';

        // Muestra/oculta el multi-select de eggs concretos segun el modo
        // elegido. Aislado a este bloque: no depende de jQuery ni de nada
        // mas cargado por el panel.
        var modeSelects = document.querySelectorAll('.ss-egg-mode');

        Array.prototype.forEach.call(modeSelects, function (select) {
            var wrapper = select.closest('.row')
                ? select.closest('.row').querySelector('.ss-egg-defined-list')
                : null;

            if (!wrapper) {
                return;
            }

            select.addEventListener('change', function () {
                wrapper.style.display = select.value === 'defined' ? '' : 'none';
            });
        });
    })();
</script>
