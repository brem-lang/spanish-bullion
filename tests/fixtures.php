<?php
// Shared test data.

declare(strict_types=1);

// A visitor in Madrid, as form-timing.js sends it.
function sample_timing(): array
{
    return [
        'landed_at'               => '2026-11-12T12:34:20.777Z',
        'submitted_at'            => '2026-11-12T12:34:40.771Z',
        'timezone'                => 'Europe/Madrid',
        'utc_offset_min'          => 60,
        'form_loaded_ms'          => 776,
        'first_continue_click_ms' => 10691,
        'continue_click_ms'       => 10691,
        'first_submit_click_ms'   => 19993,
        'submit_click_ms'         => 19993,
        'submit_clicks'           => 1,
        'form_fill_ms'            => 19217,
        'fields' => [
            'firstName'  => ['first_focus_ms' => 1094, 'last_blur_ms' => 2116, 'focus_total_ms' => 1022, 'first_input_ms' => 1836, 'last_input_ms' => 1836, 'input_count' => 1],
            'lastName'   => ['first_focus_ms' => 2120, 'last_blur_ms' => 3218, 'focus_total_ms' => 1098, 'first_input_ms' => 3010, 'last_input_ms' => 3010, 'input_count' => 1],
            'email'      => ['first_focus_ms' => 3220, 'last_blur_ms' => 4236, 'focus_total_ms' => 1016, 'first_input_ms' => 4003, 'last_input_ms' => 4003, 'input_count' => 1],
            'phone'      => ['first_focus_ms' => 4239, 'last_blur_ms' => 10619, 'focus_total_ms' => 4812, 'first_input_ms' => 4902, 'last_input_ms' => 7008, 'input_count' => 14],
            'over30'     => ['first_input_ms' => 7877, 'last_input_ms' => 7877, 'input_count' => 1],
            'experience' => ['first_input_ms' => 11764, 'last_input_ms' => 11764, 'input_count' => 1],
            'goal'       => ['first_input_ms' => 13000, 'last_input_ms' => 15376, 'input_count' => 3],
            'deposit'    => ['first_input_ms' => 16223, 'last_input_ms' => 16223, 'input_count' => 1],
        ],
    ];
}
