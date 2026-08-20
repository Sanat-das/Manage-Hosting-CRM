<?php

namespace App\Http\Requests;

use App\Models\AssetRelationship;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetRelationshipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('hosting.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $existingId = $this->route('assetRelationship')?->id;

        return [
            'parent_kind' => ['required', 'string', 'max:100', Rule::in(array_keys(AssetRelationship::ASSET_KINDS))],
            'parent_id' => ['required', 'integer', 'min:1'],
            'child_kind' => ['required', 'string', 'max:100', Rule::in(array_keys(AssetRelationship::ASSET_KINDS))],
            'child_id' => [
                'required', 'integer', 'min:1',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $parentKind = $this->input('parent_kind');
                    $parentId = $this->input('parent_id');
                    $childKind = $this->input('child_kind');

                    // Self-relationship check
                    if (
                        $parentKind !== null
                        && $childKind !== null
                        && $parentKind === $childKind
                        && $parentId == $value
                    ) {
                        $fail('Asset relationships cannot link an asset to itself.');

                        return;
                    }
                },
            ],
            'relationship_type' => ['required', 'string', Rule::in(AssetRelationship::RELATIONSHIP_TYPES)],
            'label' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
