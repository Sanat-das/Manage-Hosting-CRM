<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('products.groups') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $groupId = $this->route('product_group')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('product_groups', 'slug')->ignore($groupId)],
            'description' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'integer', 'exists:product_groups,id'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'is_hosting' => ['sometimes', 'boolean'],
        ];
    }
}
