<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Self update
    |--------------------------------------------------------------------------
    |
    | This license server updates itself from its own repository's vX.Y.Z
    | tags (Admin -> System Update). The running version is the highest
    | vX.Y.Z tag on the checked-out commit.
    |
    */

    'github_repo' => env('SELF_UPDATE_GITHUB_REPO', 'Momenoor/mie-license-server'),

    // Shared with release intake: only needed for a private repository,
    // or to lift GitHub's anonymous rate limit (60 requests an hour).
    'github_token' => env('RELEASES_GITHUB_TOKEN'),

    // Off in tests, which run inside this repository's own checkout.
    'version_from_git' => env('SELF_UPDATE_VERSION_FROM_GIT', true),

];
