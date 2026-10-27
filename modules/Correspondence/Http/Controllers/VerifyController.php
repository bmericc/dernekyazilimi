<?php

namespace Modules\Correspondence\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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
        $letter = $code === '' ? null : $this->find($code);

        return response()->view('correspondence::verify', [
            'code' => $code,
            'letter' => $letter,
        ], $code !== '' && ! $letter ? 404 : 200)->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function verify(Request $request): JsonResponse
    {
        $request->merge(['kod' => is_string($request->query('kod')) ? strtoupper(trim($request->query('kod'))) : $request->query('kod')]);
        $data = $request->validate(['kod' => ['required', 'string', 'uuid']]);
        $letter = $this->find($data['kod']);
        $response = $letter ? [
            'document_no' => $letter->document_no,
            'document_date' => $letter->document_date?->format('d.m.Y'),
            'subject' => $letter->subject,
            'status' => $letter->status,
        ] : ['message' => 'Bu kodla kayıtlı bir belge bulunamadı.'];

        return response()->json($response, $letter ? 200 : 404)
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function find(string $code): ?Letter
    {
        return Letter::where('document_id', $code)->whereIn('status', [Letter::NUMBERED, Letter::CANCELLED])->first();
    }

}
