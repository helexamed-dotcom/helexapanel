<?php
/**
 * A calendar tab that shows one of the planner pages in place.
 * The embedded template is the same one its own page renders.
 *
 * @var array  $tabs
 * @var string $tab
 * @var string $embed  template name
 */
\HeleXa\Core\View::partial('partials.cal_tabs', ['tabs' => $tabs, 'tab' => $tab]);

// Everything the controller passed, minus this wrapper's own keys and the
// renderer's locals (View::render requires the template in its own scope).
$embedData = array_diff_key(get_defined_vars(), array_flip(['embed', 'tabs', 'tab', 'template', 'data', 'file']));
\HeleXa\Core\View::partial($embed, $embedData);