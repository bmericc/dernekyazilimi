<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use App\Support\Payments\Payments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Card payment providers (several may be active, e.g. per purpose).
 */
class PaymentGatewayController extends Controller
{
    public function index(Payments $payments): View
    {
        return view('admin::payments.gateways', [
            'gateways' => PaymentGateway::orderBy('sort')->orderBy('id')->get(),
            'drivers' => config('payments.drivers'),
            'purposes' => $payments->purposes(),
        ]);
    }

    public function store(Request $request, Payments $payments): RedirectResponse
    {
        $request->validate(['driver' => ['required', Rule::in(array_keys(config('payments.drivers')))]]);
        $gateway = new PaymentGateway(['driver' => $request->input('driver')]);
        $gateway->fill($this->validated($request, $gateway, $payments))->save();
        $this->set_log('create', "Ödeme sistemi eklendi ({$gateway->name})");

        return back()->with('success-status', "{$gateway->name} eklendi.");
    }

    public function update(Request $request, PaymentGateway $gateway, Payments $payments): RedirectResponse
    {
        $gateway->fill($this->validated($request, $gateway, $payments))->save();
        $this->set_log('change', "Ödeme sistemi güncellendi ({$gateway->name})");

        return back()->with('success-status', "{$gateway->name} güncellendi.");
    }

    public function destroy(PaymentGateway $gateway): RedirectResponse
    {
        $gateway->delete();
        $this->set_log('delete', "Ödeme sistemi silindi ({$gateway->name})");

        return back()->with('success-status', "{$gateway->name} silindi.");
    }

    private function validated(Request $request, PaymentGateway $gateway, Payments $payments): array
    {
        $fields = $gateway->driverClass()::credentialFields();
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'purposes' => ['nullable', 'array'],
            'purposes.*' => [Rule::in(array_keys($payments->purposes()))],
            'sort' => ['nullable', 'integer', 'between:0,1000'],
        ];
        foreach ($fields as $key => $field) {
            // A blank secret keeps the stored one.
            $rules["credentials.$key"] = [$gateway->exists ? 'nullable' : 'required', 'string', 'max:255'];
        }
        $data = $request->validate($rules, [], ['name' => 'Ad'] + collect($fields)->mapWithKeys(fn ($field, $key) => ["credentials.$key" => $field['label']])->all());

        $credentials = $gateway->credentials ?? [];
        foreach (array_keys($fields) as $key) {
            if (filled($data['credentials'][$key] ?? null)) {
                $credentials[$key] = trim($data['credentials'][$key]);
            }
        }

        return [
            'name' => $data['name'],
            'credentials' => $credentials,
            'test_mode' => $request->boolean('test_mode'),
            'is_active' => $request->boolean('is_active'),
            'purposes' => $data['purposes'] ?? null,
            'sort' => $data['sort'] ?? 0,
        ];
    }
}
