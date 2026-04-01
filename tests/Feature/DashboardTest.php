<?php

test('homepage renders the database manager', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
    $response->assertSee('Herd Studio');
    $response->assertSee('New Connection');
});

test('dashboard renders without authentication', function () {
    $response = $this->get('/dashboard');

    $response->assertStatus(200);
    $response->assertSee('Herd Studio');
});
