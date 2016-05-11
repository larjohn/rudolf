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


Route::get("api", ["as"=>"api.start", "uses"=>"Api\\StatusController@index"]);
Route::get("api/{ver?}/cubes", ["as"=>"api.cubes", "uses"=>"Api\\CubesController@index"]);
Route::get("api/{ver?}/cubes/global/model", ["as"=>"api.cubes.model", "uses"=>"Api\\ModelController@global"]);
Route::get("api/{ver?}/cubes/global/members/{dimension}", ["as"=>"api.cubes.model", "uses"=>"Api\\MembersController@global"]);

Route::get("api/{ver?}/cubes/{name}/model", ["as"=>"api.cubes.model", "uses"=>"Api\\ModelController@index"]);
Route::get("api/{ver?}/cubes/{name}/facts", ["as"=>"api.cubes.facts", "uses"=>"Api\\FactsController@index"]);
Route::get("api/{ver?}/cubes/{name}/members/{dimension}", ["as"=>"api.cubes.members", "uses"=>"Api\\MembersController@index"]);
Route::get("api/{ver?}/cubes/global/aggregate", ["as"=>"api.cubes.aggregates", "uses"=>"Api\\AggregatesController@global"]);
Route::get("api/{ver?}/cubes/{name}/aggregate", ["as"=>"api.cubes.aggregates", "uses"=>"Api\\AggregatesController@index"]);

Route::get("api/{ver?}/info/{name}/package", ["as"=>"api.info.package", "uses"=>"Api\\PackageController@index"]);
Route::get("permit/lib", "PermitController@lib" );
Route::get("search/package", "SearchController@index" );
