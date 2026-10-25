<?php

it('returns the pasted Craftgate content as an HTML document', function () {
    $response = $this->get(route('payment.html-runner'));

    $response
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('Craftgate HTML içeriğini bu bloğun içine yapıştır.', false);
});
