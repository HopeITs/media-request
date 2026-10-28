<?php

/*
 * The media-requests part of the HR app's config/access-control.php, as is.
 * When the module moves into the HR app, nothing here needs to be copied:
 * the same entries are already in the HR file.
 */

return [
    'administrator_roles' => ['super-admin'],

    'role_baselines' => [
        'general-manager' => [
            'media-requests.view-own', 'media-requests.create', 'media-requests.work',
            'media-requests.approve', 'media-requests.manage', 'media-requests.view-log',
            'media-requests.export',
        ],
        'manager' => [
            'media-requests.view-own', 'media-requests.create', 'media-requests.work',
            'media-requests.approve', 'media-requests.manage',
            // Standalone only: the media manager needs the log here, and there
            // is no separate HR role to give it to.
            'media-requests.view-log', 'media-requests.export',
        ],
        'employee' => [
            'media-requests.view-own', 'media-requests.create', 'media-requests.work',
        ],
    ],

    'groups' => [
        'media-requests' => ['view-own', 'create', 'approve', 'work', 'manage', 'view-log', 'export'],
    ],

    'livewire' => [
        'media-requests.request-wizard' => [
            'open' => 'media-requests.create',
            'submit' => 'media-requests.create',
            'analyze' => 'media-requests.create',
        ],
        'media-requests.show' => [
            'approveProgram' => 'media-requests.approve',
            'approveMedia' => 'media-requests.approve',
            'reject' => 'media-requests.approve',
            'askInfo' => 'media-requests.view-own',
            'assign' => 'media-requests.manage',
            'hold' => 'media-requests.manage',
            'resume' => 'media-requests.manage',
            'approveInternal' => 'media-requests.manage',
            'returnToDesigner' => 'media-requests.manage',
            'claim' => 'media-requests.work',
            'start' => 'media-requests.work',
            'shareDraft' => 'media-requests.work',
            'sendResult' => 'media-requests.work',
            'markReady' => 'media-requests.work',
            'deliver' => 'media-requests.work',
            'reply' => 'media-requests.view-own',
            'approveResult' => 'media-requests.view-own',
            'requestChanges' => 'media-requests.view-own',
            'cancel' => 'media-requests.view-own',
            'nudge' => 'media-requests.view-own',
            'comment' => 'media-requests.view-own',
            'attachFiles' => 'media-requests.view-own',
        ],
        'media-requests.pack' => ['requestFiles' => 'media-requests.create'],
        'media-requests.log' => ['exportCsv' => 'media-requests.export'],
        // Standalone only: managing people is for super-admin.
        'people.index' => ['save' => 'people.manage', 'edit' => 'people.manage', 'toggleActive' => 'people.manage', 'quickAdd' => 'people.manage'],
    ],

    'livewire_exempt' => [
        // Wizard steps and tile choices only change form state; submit()
        // validates every step again and the workflow re-checks the policy.
        'media-requests.request-wizard' => ['close', 'chooseType', 'next', 'back', 'goTo', 'applyProof'],
        // Filters and re-renders; the lists are scoped to the caller's own
        // requests and programs in render(), and the routes are gated.
        'media-requests.index' => ['setActiveTab', 'refreshList'],
        'media-requests.show' => ['refreshRequest', 'applyProof', 'dropResultUpload'],
        'media-requests.log' => ['resetFilters'],
        // A filter on a read-only list; the route needs media-requests.view-log.
        'media-requests.assistant-log' => ['setFilter'],
        // The home page: every action reads and writes only the signed-in
        // person's own layout and notes (media_dashboards.user_id = auth id),
        // and the layout is cleaned against what that person may see.
        // openRequest checks the request's view policy; the notification ones act on the caller's own notifications.
        'media-requests.dashboard' => ['saveLayout', 'resetLayout', 'saveNotes', 'shiftMonth', 'thisMonth', 'openRequest', 'closeRequest', 'openNotification', 'markAllRead', 'toggleBell', 'openRequestNotes', 'bellShow', 'bellMore', 'pickDay', 'closeDay'],
        // Standalone only. Sign-in is before a session exists; markAllRead
        // and the form reset act only on the caller's own data or form state.
        // The proofreader: open/close only change the window; check is limited per person per day.
        'media-requests.proofreader-panel' => ['open', 'close', 'check', 'accept', 'again', 'openSaved', 'deleteSaved', 'download', 'updatedFile'],
        // Statistics: the page is behind media-requests.work and checks the team; the period only changes what is shown.
        'media-requests.stats' => [],
        // The files page: moving between folders only; every request shown is checked with visibleTo.
        'media-requests.drive' => ['open', 'up'],
        'auth.login' => ['login'],
        'notifications.index' => ['markAllRead'],
        'people.index' => ['create', 'cancel', 'forgetPassword'],
    ],
];
