<?php

namespace App\Domain\Applications;

/**
 * What a listing asks for on top of the application itself.
 */
enum ApplyRequirement: string
{
    case Video = 'video';
    case Test = 'test';
    case VideoAndTest = 'video_and_test';

    public function label(): string
    {
        return match ($this) {
            self::Video => __('Video'),
            self::Test => __('Test'),
            self::VideoAndTest => __('Video and test'),
        };
    }
}
