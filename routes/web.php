<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/iyzico-redirect', function(){
    return response("")
        ->header('Content-Type', 'text/html');
});

Route::get('sonuc', function(Request $request){
    return response()->json($request->all());
});