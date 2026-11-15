<?php

/*
 | CertiTrack monitoring rules.
 | Severity is assigned SERVER-SIDE from this table; the client cannot choose it.
 | Severities describe technical activity only - they are not evidence of misconduct.
 */
return [
    'consent_version' => '2026-01',

    'event_types' => [
        // type => [severity, source, label]
        'tab_switched'              => ['low',    'extension', 'Tab switched'],
        'tab_opened'                => ['low',    'extension', 'New tab opened'],
        'window_focus_lost'         => ['low',    'extension', 'Browser window lost focus'],
        'window_focus_gained'       => ['info',   'extension', 'Browser window regained focus'],
        'navigation_outside_domain' => ['medium', 'extension', 'Navigated outside approved domains'],
        'navigation_returned'       => ['info',   'extension', 'Returned to approved domain'],
        'exam_tab_closed'           => ['medium', 'extension', 'Examination tab closed'],
        'exam_tab_reopened'         => ['info',   'extension', 'Examination tab re-bound'],
        'connection_lost'           => ['medium', 'extension', 'Extension lost connection to server'],
        'connection_restored'       => ['info',   'extension', 'Extension connection restored'],
        // server-generated
        'session_started'           => ['info',   'server',    'Monitoring session started'],
        'session_ended'             => ['info',   'server',    'Monitoring session ended'],
        'heartbeat_timeout'         => ['medium', 'server',    'Heartbeat timeout'],
        'extension_disconnected'    => ['high',   'server',    'Extension disconnected'],
        'monitoring_resumed'        => ['info',   'server',    'Monitoring resumed'],
        'session_abandoned'         => ['info',   'server',    'Session marked abandoned'],
    ],

    // Used ONLY to sort sessions for manual review. Not a probability of cheating.
    'severity_weights' => ['info' => 0, 'low' => 1, 'medium' => 3, 'high' => 5],

    // Only these detail keys are ever stored (privacy: no URLs, queries, titles).
    'allowed_detail_keys' => ['host', 'direction', 'reason', 'opener_is_exam', 'queued_count', 'gap_seconds'],

    'defaults' => [
        'heartbeat_interval_sec' => 30,
        'heartbeat_timeout_sec'  => 120,
        'disconnect_after_sec'   => 240,
        'abandon_after_sec'      => 1800,
        'retention_days'         => 180,
        'require_assignment'     => false,
        'enabled_events'         => [
            'tab_switched', 'tab_opened', 'window_focus_lost', 'window_focus_gained',
            'navigation_outside_domain', 'navigation_returned', 'exam_tab_closed',
            'exam_tab_reopened', 'connection_lost', 'connection_restored',
        ],
    ],
];
