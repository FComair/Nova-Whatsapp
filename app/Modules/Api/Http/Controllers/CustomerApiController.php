<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Concerns\RespondsWithJson;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerDirectory;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Client final identifié par son numéro WhatsApp (scope customers:write) : retrouvé ou créé, sans doublon.
 */
final class CustomerApiController
{
    use RespondsWithJson;

    public function __construct(private readonly CurrentTenant $current, private readonly CustomerDirectory $customers) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'name' => ['nullable', 'string', 'max:120'],
            'language' => ['nullable', 'in:fr,nl,en'],
        ]);

        $existed = Customer::query()->where('phone_e164', $data['phone'])->exists();
        $customer = $this->customers->findOrCreate($this->current->organization() ?? abort(403), $data['phone'], $data['name'] ?? null, $data['language'] ?? null);

        return new JsonResponse(['data' => $this->customer($customer)], $existed ? 200 : 201);
    }
}
