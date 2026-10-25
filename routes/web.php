<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/iyzico-redirect', function () {
    return response('')
        ->header('Content-Type', 'text/html');
});

Route::get('sonuc', function (Request $request) {
    return response()->json($request->all());
});

if (app()->environment(['local', 'testing'])) {
    Route::get('/payment/html-runner', function () {
        $craftgateHtml = <<<'HTML'
<!-- Craftgate HTML içeriğini bu bloğun içine yapıştır. -->
HTML;

        $decodedPayload = json_decode($craftgateHtml, true);

        if (is_array($decodedPayload)) {
            $decodedHtml = data_get($decodedPayload, 'clientAction.html');
        } elseif (is_string($decodedPayload)) {
            $decodedHtml = $decodedPayload;
        } else {
            $decodedHtml = json_decode('"'.$craftgateHtml.'"', true);
        }

        return response(is_string($decodedHtml) ? $decodedHtml : $craftgateHtml)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    })
        ->name('payment.html-runner');
}
