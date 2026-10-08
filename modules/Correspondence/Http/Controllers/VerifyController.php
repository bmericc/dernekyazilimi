<?php

namespace Modules\Correspondence\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Correspondence\Models\Letter;

/**
 * Public page that confirms a letter by the verification code printed on it.
 * It shows the number, date and subject only, never the letter itself.
 */
class VerifyController extends Controller
{
    public function show(Request $request): Response
    {
        $code = strtoupper(trim((string) $request->query('kod')));
        $letter = $code === '' ? null : Letter::where('document_id', $code)->whereIn('status', [Letter::NUMBERED, Letter::CANCELLED])->first();

        return response()->view('correspondence::verify', [
            'code' => $code,
            'letter' => $letter,
        ], $code !== '' && ! $letter ? 404 : 200)->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
