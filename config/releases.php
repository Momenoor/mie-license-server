<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Release intake
    |--------------------------------------------------------------------------
    |
    | New releases arrive two ways, both creating an UNPUBLISHED release
    | (tick "Published" in the admin panel to offer it to installations):
    |
    | - pushed: the app repository's GitHub Action (on every vX.Y.Z tag)
    |   POSTs to /api/v1/releases with `Authorization: Bearer {api_token}`.
    | - pulled: the "Sync from GitHub" button on the Releases page reads the
    |   repository's releases and tags, for anything a push missed.
    |
    */

    'api_token' => env('RELEASES_API_TOKEN'),

    'github_repo' => env('RELEASES_GITHUB_REPO', 'Momenoor/wakeel'),

    // Only needed for a private repository, or to lift GitHub's anonymous
    // rate limit (60 requests an hour).
    'github_token' => env('RELEASES_GITHUB_TOKEN'),

    'product' => env('RELEASES_PRODUCT', 'mie'),

];
