<?php

namespace Pterodactyl\Extensions\ServerSplitter\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Extensions\ServerSplitter\Models\ServerSplit;
use Pterodactyl\Extensions\ServerSplitter\Models\SplitterEggRule;
use Pterodactyl\Extensions\ServerSplitter\Models\SplitterLimit;
use Pterodactyl\Extensions\ServerSplitter\Models\SplitterSetting;
use Pterodactyl\Extensions\ServerSplitter\Services\ResourceCalculator;
use Pterodactyl\Extensions\ServerSplitter\Services\SplitterService;

class AdminController extends Controller
{
    public function __construct(protected SplitterService $splitter)
    {
    }

    public function index(): View
    {
        return view('serversplitter::admin.settings', [
            'settings' => $this->splitter->settings(),
            'eggs' => Egg::query()->with('nest')->orderBy('name')->get(),
            'rules' => SplitterEggRule::query()->with('egg.nest')->get(),
            'limits' => SplitterLimit::query()->with('server')->get(),
            'splits' => ServerSplit::query()->with(['server', 'parent'])->orderByDesc('id')->limit(50)->get(),
            'totalSplits' => ServerSplit::query()->count(),
            'hasApiKey' => (string) (SplitterSetting::read('api_key_hash') ?? '') !== '',
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'max_splits' => 'required|integer|min:0|max:100',
            'resize_mode' => 'required|in:difference,distribute',
            'power_action' => 'required|in:none,restart,stop,kill',
            'min_memory' => 'required|integer|min:0',
            'min_disk' => 'required|integer|min:0',
            'min_cpu' => 'required|integer|min:0|max:100000',
            'reserve_memory' => 'required|integer|min:0',
            'reserve_disk' => 'required|integer|min:0',
            'reserve_cpu' => 'required|integer|min:0|max:100000',
            'child_name_prefix' => 'required|string|max:32',
        ]);

        $data['enabled'] = $request->boolean('enabled');
        $data['allow_disk'] = $request->boolean('allow_disk');
        $data['allow_cpu'] = $request->boolean('allow_cpu');
        $data['allow_unlisted_eggs'] = $request->boolean('allow_unlisted_eggs');
        $data['allow_child_deletion'] = $request->boolean('allow_child_deletion');

        SplitterSetting::write($data);

        return redirect()->route('admin.serversplitter.index')
            ->with('serversplitter:success', 'Configuracion guardada correctamente.');
    }

    public function storeEggRule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'egg_id' => 'required|integer|exists:eggs,id',
            'min_memory' => 'nullable|integer|min:0',
            'min_disk' => 'nullable|integer|min:0',
            'min_cpu' => 'nullable|integer|min:0',
            'max_memory' => 'nullable|integer|min:0',
            'max_disk' => 'nullable|integer|min:0',
            'max_cpu' => 'nullable|integer|min:0',
        ]);

        $data['allowed'] = $request->boolean('allowed');

        SplitterEggRule::query()->updateOrCreate(['egg_id' => $data['egg_id']], $data);

        return redirect()->route('admin.serversplitter.index')
            ->with('serversplitter:success', 'Regla de egg guardada.');
    }

    public function destroyEggRule(int $rule): RedirectResponse
    {
        SplitterEggRule::query()->whereKey($rule)->delete();

        return redirect()->route('admin.serversplitter.index')
            ->with('serversplitter:success', 'Regla de egg eliminada.');
    }

    public function storeLimit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'server_id' => 'required|integer|exists:servers,id',
            'max_splits' => 'nullable|integer|min:0',
            'max_memory' => 'nullable|integer|min:0',
            'max_disk' => 'nullable|integer|min:0',
            'max_cpu' => 'nullable|integer|min:0',
        ]);

        SplitterLimit::query()->updateOrCreate(['server_id' => $data['server_id']], $data);

        return redirect()->route('admin.serversplitter.index')
            ->with('serversplitter:success', 'Limites del servidor guardados.');
    }

    public function destroyLimit(int $limit): RedirectResponse
    {
        SplitterLimit::query()->whereKey($limit)->delete();

        return redirect()->route('admin.serversplitter.index')
            ->with('serversplitter:success', 'Limites del servidor eliminados.');
    }

    public function destroySplit(int $split): RedirectResponse
    {
        $model = ServerSplit::query()->with(['server', 'parent'])->find($split);

        if ($model === null) {
            return redirect()->route('admin.serversplitter.index')
                ->with('serversplitter:error', 'Esa division ya no existe.');
        }

        try {
            $this->splitter->deleteSplit($model, $this->currentUser(), true);
        } catch (\Throwable $e) {
            return redirect()->route('admin.serversplitter.index')
                ->with('serversplitter:error', $e->getMessage());
        }

        return redirect()->route('admin.serversplitter.index')
            ->with('serversplitter:success', 'Division eliminada y recursos devueltos al padre.');
    }

    public function unlock(int $server): RedirectResponse
    {
        $model = Server::query()->find($server);

        if ($model === null) {
            return redirect()->route('admin.serversplitter.index')
                ->with('serversplitter:error', 'Servidor no encontrado.');
        }

        $this->splitter->locks()->release($model->id, '');
        \Illuminate\Support\Facades\DB::table('serversplitter_locks')->where('server_id', $model->id)->delete();

        return redirect()->route('admin.serversplitter.index')
            ->with('serversplitter:success', 'Bloqueo liberado.');
    }

    public function rebuildInfo(): RedirectResponse
    {
        SplitterSetting::flush();
        $this->splitter->locks()->pruneExpired();

        return redirect()->route('admin.serversplitter.index')
            ->with('serversplitter:success', 'Cache de configuracion limpiada y bloqueos caducados eliminados.');
    }

    protected function currentUser(): \Pterodactyl\Models\User
    {
        /** @var \Pterodactyl\Models\User $user */
        $user = auth()->user();

        return $user;
    }

    public function modes(): array
    {
        return [
            ResourceCalculator::MODE_DIFFERENCE => 'Restar / sumar la diferencia',
            ResourceCalculator::MODE_DISTRIBUTE => 'Reparto equitativo',
        ];
    }
}