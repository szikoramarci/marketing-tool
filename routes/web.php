<?php

use App\Http\Controllers\QuizController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/campaigns/{campaign:slug}', [QuizController::class, 'start'])->name('quiz.start');
Route::get('/preview/{configVersion}', [QuizController::class, 'preview'])->middleware('auth')->name('quiz.preview');
Route::get('/sessions/{quizSession}', [QuizController::class, 'show'])->name('quiz.show');
Route::post('/sessions/{quizSession}/question-shown', [QuizController::class, 'questionShown'])->name('quiz.question-shown');
Route::post('/sessions/{quizSession}/answers', [QuizController::class, 'submit'])->name('quiz.submit');
Route::get('/results/{quizSession}', [QuizController::class, 'result'])->name('results.show');
