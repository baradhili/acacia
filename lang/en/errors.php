<?php

// Strings for the custom error pages (500 server error, 503
// maintenance). Kept generic on purpose — the pages render when
// things are broken, so they must not depend on the app's layouts,
// built assets or any runtime state beyond the translator. en is the
// complete base; en_AU overrides only differing keys.
return [
    'back_home' => 'Back to the dashboard',

    'server_error_code' => '500',
    'server_error_title' => 'Something went wrong',
    'server_error_body' => 'The error has been logged for the team to look at. Please try again, or come back shortly if it keeps happening.',

    'maintenance_code' => '503',
    'maintenance_title' => "We'll be right back",
    'maintenance_body' => 'Acacia is down for scheduled maintenance. Please try again in a few minutes.',
];
