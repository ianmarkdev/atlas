<?php

namespace App\Domain\Knowledge;

enum LanguageProficiency: string
{
    case Native = 'native';
    case Fluent = 'fluent';
    case Professional = 'professional';
    case Conversational = 'conversational';
    case Basic = 'basic';
}
