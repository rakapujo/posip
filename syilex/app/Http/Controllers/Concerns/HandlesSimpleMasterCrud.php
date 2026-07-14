<?php

namespace App\Http\Controllers\Concerns;

use App\Services\SettingService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * DRY CRUD for simple master entities (kode + nama + status pattern).
 *
 * @phpstan-type SimpleMasterCrudConfig array{
 *     model: class-string<Model>,
 *     permission_prefix: string,
 *     resource_key: string,
 *     collection_key: string,
 *     entity_label: string,
 *     kode_field: string,
 *     nama_field: string,
 *     unique_table: string,
 *     search_fields: list<string>,
 *     sortable_fields: list<string>,
 *     export_filename_prefix: string,
 *     export_factory: Closure,
 *     list_select: list<string>,
 *     list_order_field: string,
 *     messages: array{
 *         created: string,
 *         updated: string,
 *         activated: string,
 *         deactivated: string,
 *         deleted: string,
 *         not_found: string,
 *     },
 *     extra_store_rules?: array<string, mixed>,
 *     extra_update_rules?: array<string, mixed>,
 *     has_customer_discount?: bool,
 *     before_store?: Closure(Request, array): (?JsonResponse),
 *     before_update?: Closure(Request, Model, array): (?JsonResponse),
 *     mutate_store?: Closure(array): array,
 *     mutate_update?: Closure(array, Model): array,
 *     can_delete?: Closure(Model): (?JsonResponse),
 *     apply_index_filters?: Closure(Builder, Request): void,
 *     apply_list_filters?: Closure(Builder, Request): void,
 *     show_relations?: list<string>,
 *     index_with?: list<string>,
 *     apply_sort?: Closure(Builder, Request): void,
 *     before_toggle?: Closure(Model): (?JsonResponse),
 *     after_show?: Closure(Model): void,
 *     after_store?: Closure(Model): void,
 *     after_update?: Closure(Model): void,
 *     after_toggle?: Closure(Model): void,
 * }
 */
trait HandlesSimpleMasterCrud
{
    /**
     * @return SimpleMasterCrudConfig
     */
    abstract protected function simpleMasterCrudConfig(): array;

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->authorizeSimpleMaster('view')) {
            return $response;
        }

        $config = $this->simpleMasterCrudConfig();
        $query = $config['model']::query();

        if (! empty($config['index_with'])) {
            $query->with($config['index_with']);
        }

        if (isset($config['apply_index_filters'])) {
            ($config['apply_index_filters'])($query, $request);
        } else {
            $this->applyDefaultIndexFilters($query, $request, $config);
        }

        if (isset($config['apply_sort'])) {
            ($config['apply_sort'])($query, $request);
        } else {
            $sortField = $request->input('sort_field', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';

            if (in_array($sortField, $config['sortable_fields'], true)) {
                $query->orderBy($sortField, $sortOrder);
            } else {
                $query->orderBy('created_at', $sortOrder);
            }
        }

        $paginated = $query->paginate($this->getPerPage($request));

        return $this->success([
            $config['collection_key'] => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->authorizeSimpleMaster('create')) {
            return $response;
        }

        $config = $this->simpleMasterCrudConfig();
        $kodeField = $config['kode_field'];
        $namaField = $config['nama_field'];

        $rules = array_merge([
            $kodeField => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9-]+$/',
                'unique:'.$config['unique_table'].','.$kodeField,
            ],
            $namaField => 'required|string|max:100',
            'status' => 'required|in:active,inactive',
        ], $config['extra_store_rules'] ?? []);

        $validated = $request->validate($rules, [
            "{$kodeField}.regex" => 'Kode hanya boleh berisi huruf, angka, dan tanda hubung (-)',
        ]);

        if (! empty($config['has_customer_discount'])) {
            if ($response = $this->validateCustomerDiscountFields($request, $validated)) {
                return $response;
            }
        }

        if (isset($config['before_store'])) {
            if ($response = ($config['before_store'])($request, $validated)) {
                return $response;
            }
        }

        $validated[$kodeField] = SettingService::formatCode($validated[$kodeField]);
        $validated[$namaField] = SettingService::formatName($validated[$namaField]);

        if (isset($config['mutate_store'])) {
            $validated = ($config['mutate_store'])($validated);
        }

        $model = $config['model']::create($validated);

        if (isset($config['after_store'])) {
            ($config['after_store'])($model);
        }

        return $this->success([
            $config['resource_key'] => $model,
        ], $config['messages']['created'], 201);
    }

    public function show(string $ulid): JsonResponse
    {
        if ($response = $this->authorizeSimpleMaster('view')) {
            return $response;
        }

        $config = $this->simpleMasterCrudConfig();
        $query = $config['model']::query();

        $relations = array_merge(
            ['createdBy:id,name,email', 'updatedBy:id,name,email'],
            $config['show_relations'] ?? [],
        );
        $query->with($relations);

        $model = $query->where('ulid', $ulid)->first();

        if (! $model) {
            return $this->error($config['messages']['not_found'], 404);
        }

        if (isset($config['after_show'])) {
            ($config['after_show'])($model);
        }

        return $this->success([
            $config['resource_key'] => $model,
        ]);
    }

    public function update(Request $request, string $ulid): JsonResponse
    {
        if ($response = $this->authorizeSimpleMaster('update')) {
            return $response;
        }

        $config = $this->simpleMasterCrudConfig();
        $model = $config['model']::where('ulid', $ulid)->first();

        if (! $model) {
            return $this->error($config['messages']['not_found'], 404);
        }

        $namaField = $config['nama_field'];
        $rules = array_merge([
            $namaField => 'required|string|max:100',
            'status' => 'required|in:active,inactive',
        ], $config['extra_update_rules'] ?? []);

        $validated = $request->validate($rules);

        if (! empty($config['has_customer_discount'])) {
            if ($response = $this->validateCustomerDiscountFields($request, $validated)) {
                return $response;
            }
        }

        if (isset($config['before_update'])) {
            if ($response = ($config['before_update'])($request, $model, $validated)) {
                return $response;
            }
        }

        $validated[$namaField] = SettingService::formatName($validated[$namaField]);

        if (isset($config['mutate_update'])) {
            $validated = ($config['mutate_update'])($validated, $model);
        }

        $model->update($validated);

        if (isset($config['after_update'])) {
            ($config['after_update'])($model);
        }

        return $this->success([
            $config['resource_key'] => $model,
        ], $config['messages']['updated']);
    }

    public function toggleStatus(string $ulid): JsonResponse
    {
        if ($response = $this->authorizeSimpleMaster('update')) {
            return $response;
        }

        $config = $this->simpleMasterCrudConfig();
        $model = $config['model']::where('ulid', $ulid)->first();

        if (! $model) {
            return $this->error($config['messages']['not_found'], 404);
        }

        if (isset($config['before_toggle'])) {
            if ($response = ($config['before_toggle'])($model)) {
                return $response;
            }
        }

        $newStatus = $model->status === 'active' ? 'inactive' : 'active';
        $model->update(['status' => $newStatus]);

        if (isset($config['after_toggle'])) {
            ($config['after_toggle'])($model);
        }

        $message = $newStatus === 'active'
            ? $config['messages']['activated']
            : $config['messages']['deactivated'];

        return $this->success([$config['resource_key'] => $model], $message);
    }

    public function destroy(string $ulid): JsonResponse
    {
        if ($response = $this->authorizeSimpleMaster('delete')) {
            return $response;
        }

        $config = $this->simpleMasterCrudConfig();
        $model = $config['model']::where('ulid', $ulid)->first();

        if (! $model) {
            return $this->error($config['messages']['not_found'], 404);
        }

        if (isset($config['can_delete'])) {
            if ($response = ($config['can_delete'])($model)) {
                return $response;
            }
        }

        $model->delete();

        return $this->success(null, $config['messages']['deleted']);
    }

    public function export(Request $request)
    {
        if ($response = $this->authorizeSimpleMaster('view')) {
            return $response;
        }

        $config = $this->simpleMasterCrudConfig();
        $filename = $config['export_filename_prefix'].'_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(
            ($config['export_factory'])($request),
            $filename,
        );
    }

    public function list(Request $request): JsonResponse
    {
        $config = $this->simpleMasterCrudConfig();
        $query = $config['model']::active()
            ->select($config['list_select'])
            ->orderBy($config['list_order_field']);

        if (isset($config['apply_list_filters'])) {
            ($config['apply_list_filters'])($query, $request);
        }

        $items = $query->get()->makeVisible('id');

        return $this->success([
            $config['collection_key'] => $items,
        ]);
    }

    protected function authorizeSimpleMaster(string $action): ?JsonResponse
    {
        $prefix = $this->simpleMasterCrudConfig()['permission_prefix'];

        if (! auth()->user()->can("{$prefix}.{$action}")) {
            return $this->error('Unauthorized', 403);
        }

        return null;
    }

    /**
     * @param  SimpleMasterCrudConfig  $config
     */
    protected function applyDefaultIndexFilters(Builder $query, Request $request, array $config): void
    {
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search, $config) {
                foreach ($config['search_fields'] as $field) {
                    $q->orWhere($field, 'like', "%{$search}%");
                }
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
    }

    protected function validateCustomerDiscountFields(Request $request, array $validated): ?JsonResponse
    {
        if (($request->filled('diskon_tipe') && $request->diskon_tipe !== 'none') || $request->filled('diskon_nilai')) {
            if (! auth()->user()->can('customer-discount.manage')) {
                return $this->error('Tidak memiliki izin mengubah diskon customer', 403);
            }

            if (($validated['diskon_tipe'] ?? 'none') === 'percent' && ($validated['diskon_nilai'] ?? 0) > 100) {
                return $this->error('Diskon persen maksimal 100%', 422);
            }
        }

        return null;
    }
}
