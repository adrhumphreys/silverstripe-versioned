<?php

namespace SilverStripe\Versioned;

use SilverStripe\View\TemplateGlobalProvider;

class Template implements TemplateGlobalProvider
{
    public static function get_template_global_variables(): array
    {
        return [
            'CurrentReadingMode' => 'getReadingMode'
        ];
    }

    public function getReadingMode(): string
    {
        return Backend::singleton()->getReadingMode();
    }
}
