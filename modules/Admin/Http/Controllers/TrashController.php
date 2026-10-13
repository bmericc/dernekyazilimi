<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Trash;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Records deleted from the panel: nothing is removed for good, a deleted
 * record waits here until it is restored.
 */
class TrashController extends Controller
{
    public function index(Trash $trash): View
    {
        return view('admin::trash.index', ['groups' => $trash->all()]);
    }

    public function restore(Trash $trash, string $type, int $id): RedirectResponse
    {
        abort_unless($record = $trash->find($type, $id), 404);

        $record->restore();
        $this->set_log('change', 'Silinen kayıt geri getirildi: '.Trash::label($record));

        return back()->with('success-status', '"'.Trash::label($record).'" geri getirildi.');
    }
}
