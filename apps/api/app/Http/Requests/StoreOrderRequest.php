<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Storefront é público; o tenant já foi resolvido no middleware.
    }

    public function rules(): array
    {
        return [
            'customer.name' => ['required', 'string', 'max:120'],
            'customer.phone' => ['required', 'string', 'max:20'],
            'customer.email' => ['nullable', 'email', 'max:180'],
            'customer.cpf' => ['nullable', 'string', 'max:20'],

            'fulfillment' => ['required', Rule::in(Order::FULFILLMENTS)],

            'address' => ['required_if:fulfillment,delivery', 'array'],
            'address.street' => ['required_with:address', 'string', 'max:180'],
            'address.number' => ['nullable', 'string', 'max:20'],
            'address.complement' => ['nullable', 'string', 'max:120'],
            'address.district' => ['nullable', 'string', 'max:120'],
            'address.city' => ['required_with:address', 'string', 'max:120'],
            'address.state' => ['required_with:address', 'string', 'size:2'],
            'address.zip' => ['nullable', 'string', 'max:12'],

            'payment_method' => [
                'required',
                Rule::in([...Order::PAY_ON_DELIVERY_METHODS, ...Order::ONLINE_PAYMENT_METHODS]),
            ],

            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.modifier_ids' => ['nullable', 'array', 'max:20'],
            'items.*.modifier_ids.*' => ['integer'],

            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Note que preço NÃO é aceito do cliente em nenhum campo. Todo valor é
     * recalculado no OrderService a partir do banco.
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Seu carrinho está vazio.',
            'address.required_if' => 'Informe o endereço de entrega.',
        ];
    }
}
