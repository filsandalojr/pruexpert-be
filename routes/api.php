<?php

use App\Http\Controllers\ReportsController;
use App\Http\Controllers\DigitalTriggerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::get('/getCourses/{id}', [ReportsController::class, 'index']);
Route::get('/getCourseDetails/{id}', [ReportsController::class, 'getCourseDetails']);
Route::post('/getUser', [ReportsController::class, 'getUser']);
Route::get('/learningPaths', [ReportsController::class, 'getLearningPaths']);
Route::post('/completeModule', [ReportsController::class, 'completeModule']);


Route::post('/completePruexpert', [DigitalTriggerController::class, 'completeModule']);
Route::get('/comments', [DigitalTriggerController::class, 'getComments']);
Route::get('/nexGenReports', [DigitalTriggerController::class, 'getNexGenReports']);



