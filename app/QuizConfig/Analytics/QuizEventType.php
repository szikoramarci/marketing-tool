<?php

namespace App\QuizConfig\Analytics;

enum QuizEventType: string
{
    case SessionStarted = 'session_started';
    case QuestionShown = 'question_shown';
    case QuestionAnswered = 'question_answered';
    case EvaluationCompleted = 'evaluation_completed';
    case ResultPageViewed = 'result_page_viewed';

    // Not dispatched anywhere yet — no code path exists (email capture is
    // static-only, Lead wiring and email sending are separate, later tasks).
    case EmailProvided = 'email_provided';
    case CtaClicked = 'cta_clicked';
    case EmailSent = 'email_sent';
    case EmailOpened = 'email_opened';
}
