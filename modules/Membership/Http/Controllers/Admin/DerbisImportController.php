<?php

namespace Modules\Membership\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Modules\Membership\Support\DerbisImport;

/**
 * DERBİS member list import: upload with options, a preview of every row,
 * then the import. The file waits on the private disk between the steps and
 * is deleted after the import.
 */
class DerbisImportController extends Controller
{
    private const DIRECTORY = 'imports/derbis';

    private const SESSION = 'membership.derbis_import';

    public function create(DerbisImport $import): View
    {
        $fields = $this->customFields();

        return view('membership::admin.import.create', [
            'fields' => $fields,
            'mapping' => old('fields', session(self::SESSION.'.options.fields') ?? $import->defaultFieldMapping($fields)),
        ]);
    }

    public function store(Request $request, DerbisImport $import): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:xlsx,csv,txt'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['nullable', 'integer', 'exists:custom_fields,id'],
        ], [], ['file' => 'Dosya']);

        $this->discard();
        $this->purgeOld();

        $extension = strtolower($request->file('file')->getClientOriginalExtension()) === 'csv' ? 'csv' : 'xlsx';
        $path = $request->file('file')->storeAs(self::DIRECTORY, Str::uuid().'.'.$extension, 'local');

        $read = $import->read(Storage::disk('local')->path($path), $extension);
        if ($read['error']) {
            Storage::disk('local')->delete($path);

            return back()->withInput()->withErrors(['file' => $read['error']]);
        }

        session([self::SESSION => [
            'path' => $path,
            'extension' => $extension,
            'name' => $request->file('file')->getClientOriginalName(),
            'options' => [
                'overwrite' => $request->boolean('overwrite'),
                'fields' => array_intersect_key($data['fields'] ?? [], array_flip(DerbisImport::EXTRA)),
            ],
        ]]);

        return redirect()->route('admin.memberships.import.preview');
    }

    public function preview(DerbisImport $import): View|RedirectResponse
    {
        if (! $state = $this->state()) {
            return redirect()->route('admin.memberships.import')->with('danger-status', 'Önce dosyayı yükleyin.');
        }

        $plan = $import->plan($import->read(Storage::disk('local')->path($state['path']), $state['extension'])['records'], $state['options']);
        $fields = CustomField::whereIn('id', array_filter($state['options']['fields']))->get()->keyBy('id');

        return view('membership::admin.import.preview', [
            'plan' => $plan,
            'counts' => collect($plan)->countBy('action'),
            'missing' => $import->missingMembers($plan),
            'state' => $state,
            'mappedFields' => collect($state['options']['fields'])->map(fn ($id) => $fields->get($id))->filter(),
        ]);
    }

    public function apply(DerbisImport $import): RedirectResponse
    {
        if (! $state = $this->state()) {
            return redirect()->route('admin.memberships.import')->with('danger-status', 'Önce dosyayı yükleyin.');
        }

        $plan = $import->plan($import->read(Storage::disk('local')->path($state['path']), $state['extension'])['records'], $state['options']);
        $summary = $import->apply($plan, $state['options']);
        $this->discard();

        $message = "DERBİS listesi aktarıldı: {$summary['new']} yeni kişi, {$summary['new_membership']} mevcut kişiye yeni üyelik, {$summary['update']} güncelleme, {$summary['unchanged']} değişmeyen, {$summary['skip']} atlanan satır.";
        $this->set_log('change', $message);

        return redirect()->route('admin.memberships')->with('success-status', $message);
    }

    public function cancel(): RedirectResponse
    {
        $this->discard();

        return redirect()->route('admin.memberships.import')->with('success-status', 'İçe aktarma iptal edildi, dosya silindi.');
    }

    private function state(): ?array
    {
        $state = session(self::SESSION);

        return $state && Storage::disk('local')->exists($state['path']) ? $state : null;
    }

    private function discard(): void
    {
        if ($path = session(self::SESSION.'.path')) {
            Storage::disk('local')->delete($path);
        }
        session()->forget(self::SESSION);
    }

    /**
     * Files left behind by imports that were never finished (member data).
     */
    private function purgeOld(): void
    {
        foreach (Storage::disk('local')->files(self::DIRECTORY) as $file) {
            if (Storage::disk('local')->lastModified($file) < now()->subDay()->getTimestamp()) {
                Storage::disk('local')->delete($file);
            }
        }
    }

    private function customFields(): Collection
    {
        return CustomField::active()->whereIn('applies_to', ['person', 'both'])->orderBy('group')->orderBy('sort')->get();
    }
}
