<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Upload Settings
    |--------------------------------------------------------------------------
    |
    | Default settings for image processing
    |
    */
    'default_quality' => env('UPLOAD_DEFAULT_QUALITY', 85),
    'default_format' => env('UPLOAD_DEFAULT_FORMAT', 'webp'),

    /*
    |--------------------------------------------------------------------------
    | Folder Configurations
    |--------------------------------------------------------------------------
    |
    | Each folder can have its own settings for max dimensions, file size,
    | and allowed file types. Images will be resized proportionally.
    |
    | max_width/max_height: Maximum dimensions in pixels (maintains aspect ratio)
    | max_size: Maximum file size in KB before processing
    | allowed_types: Allowed input file extensions
    | quality: Output quality (1-100), uses default if not specified
    |
    */
    'folders' => [
        'settings' => [
            'max_width' => 800,
            'max_height' => 800,
            'max_size' => 2048, // 2MB
            'allowed_types' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
            'quality' => 85,
        ],

        'products' => [
            'max_width' => 1200,
            'max_height' => 1200,
            'max_size' => 5120, // 5MB
            'allowed_types' => ['jpg', 'jpeg', 'png', 'webp'],
            'quality' => 85,
        ],

        'users' => [
            'max_width' => 400,
            'max_height' => 400,
            'max_size' => 1024, // 1MB
            'allowed_types' => ['jpg', 'jpeg', 'png', 'webp'],
            'quality' => 90,
        ],

        'avatars' => [
            'max_width' => 400,
            'max_height' => 400,
            'max_size' => 1024, // 1MB
            'allowed_types' => ['jpg', 'jpeg', 'png', 'webp'],
            'quality' => 90,
        ],

        'documents' => [
            'max_width' => 1600,
            'max_height' => 1600,
            'max_size' => 10240, // 10MB
            'allowed_types' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
            'quality' => 90,
        ],

        'payments' => [
            'max_width' => 400,
            'max_height' => 400,
            'max_size' => 1024, // 1MB
            'allowed_types' => ['jpg', 'jpeg', 'png', 'webp'],
            'quality' => 90,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    |
    | The disk to use for storing uploaded files
    |
    */
    'disk' => env('UPLOAD_DISK', 'public'),
];
