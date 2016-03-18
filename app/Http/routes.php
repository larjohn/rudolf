<?php

/*
|--------------------------------------------------------------------------
| Routes File
|--------------------------------------------------------------------------
|
| Here is where you will register all of the routes in an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the controller to call when that URI is requested.
|
*/

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| This route group applies the "web" middleware group to every route
| it contains. The "web" middleware group is defined in your HTTP
| kernel and includes session state, CSRF protection, and more.
|
*/


Route::get("start", "StartController@index");
Route::get("api", ["as"=>"api.start", "uses"=>"Api\\StatusController@index"]);
Route::get("api/cubes", ["as"=>"api.cubes", "uses"=>"Api\\CubesController@index"]);
Route::get("api/cubes/{name}/model", ["as"=>"api.cubes.model", "uses"=>"Api\\ModelController@index"]);
Route::get("api/cubes/{name}/facts", ["as"=>"api.cubes.facts", "uses"=>"Api\\FactsController@index"]);

Route::get("start/api/datasets", ["as"=>"start.api.datasets", "uses"=>"StartController@datasets"]);
Route::get("start/api/dataset/observations/dimensions", ["as"=>"start.api.dataset.observations.dimensions", "uses"=>"StartController@getObservationDimensions"]);
Route::get("start/api/dataset/cubes/{alias}/model", ["as"=>"start.api.dataset.observations.model", "uses"=>"StartController@getObservationDimensions2"]);
Route::get("start/api/dataset/cubes/{alias}/facts", ["as"=>"start.api.dataset.observations.facts2", "uses"=>"StartController@getObservations3"]);
Route::get("start/api/dataset/observations/measures", ["as"=>"start.api.dataset.observations.measures", "uses"=>"StartController@getObservationMeasures"]);
Route::get("start/api/dataset/facts", ["as"=>"start.api.dataset.facts", "uses"=>"StartController@getObservations2"]);
Route::get("start/api/dataset/observations", ["as"=>"start.api.dataset.observations", "uses"=>"StartController@getObservations"]);
Route::get("start/api/dataset/oneonone", ["as"=>"start.api.dataset.oneonone", "uses"=>"StartController@oneOnOneSlicer"]);

Route::group(['middleware' => ['web']], function () {
    //
});
