<?php

return [

    // Kill switch of the professionals portal (/api/pro/* and the owner-side
    // access endpoints). Off => PortalProfesionalesHabilitado answers 404 on
    // every one of those routes, even to unauthenticated callers. Read at
    // request time (not at route registration) so it survives `route:cache`.
    'habilitado' => (bool) env('PORTAL_PROFESIONALES_HABILITADO', false),

    // Public URL of the portal frontend; used in the invitation mails.
    'url' => env('PORTAL_PROFESIONALES_URL', 'https://profesionales.turnetto.com'),

];
