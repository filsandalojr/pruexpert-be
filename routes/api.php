<?php

use App\Http\Controllers\ReportsController;
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

Route::get('/getCourses', [ReportsController::class, 'index']);
Route::get('/getCourseDetails/{id}', [ReportsController::class, 'getCourseDetails']);
Route::post('/getUser', [ReportsController::class, 'getUser']);
Route::post('/completeModule', [ReportsController::class, 'completeModule']);


