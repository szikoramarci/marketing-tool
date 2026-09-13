<?php

namespace App\QuizConfig\Validation;

enum IssueSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
