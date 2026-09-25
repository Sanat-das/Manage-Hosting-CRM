<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The published AdminLTE form overrides must key the required marker off the
 * attribute VALUE. Callers pass `:required="false"` for optional schema
 * fields, and a key-existence check (`has`) used to render the marker anyway.
 */
class ModuleConfigFieldRenderingTest extends TestCase
{
    public function test_optional_config_fields_render_without_required_marker(): void
    {
        $html = view('admin.products._module_config_fields', [
            'schema' => [
                'fields' => [
                    ['key' => 'plan', 'label' => 'Plan / hardware profile', 'type' => 'text', 'required' => false, 'default' => ''],
                    ['key' => 'cpu', 'label' => 'vCPUs', 'type' => 'number', 'required' => false, 'default' => 2],
                ],
            ],
            'cfg' => [],
        ])->render();

        $this->assertStringNotContainsString('mh-required', $html);
        $this->assertStringNotContainsString('(required)', $html);
    }

    public function test_required_config_fields_keep_the_required_marker(): void
    {
        $html = view('admin.products._module_config_fields', [
            'schema' => [
                'fields' => [
                    ['key' => 'plan', 'label' => 'Plan', 'type' => 'text', 'required' => true, 'default' => ''],
                ],
            ],
            'cfg' => [],
        ])->render();

        $this->assertStringContainsString('mh-required', $html);
        $this->assertStringContainsString('(required)', $html);
    }

    public function test_component_marker_tracks_the_boolean_value(): void
    {
        $optional = Blade::render('<x-adminlte-input name="t" label="T" :required="false" />');
        $this->assertStringNotContainsString('mh-required', $optional);

        $required = Blade::render('<x-adminlte-input name="t" label="T" required />');
        $this->assertStringContainsString('mh-required', $required);
    }
}
