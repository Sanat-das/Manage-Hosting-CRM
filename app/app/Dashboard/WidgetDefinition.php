<?php

namespace App\Dashboard;

/**
 * Read-only definition of a dashboard widget.
 *
 * The registry (WidgetRegistry) is the single source of truth for the widget
 * catalogue; the controller resolves each enabled key to one of these and
 * feeds the provider method's data into the widget's partial view.
 */
readonly class WidgetDefinition
{
    public function __construct(
        public string $key,
        public string $title,
        public string $icon,
        public string $description,
        public string $size,
        public string $view,
        public string $provider,
    ) {}
}