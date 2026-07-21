<?php

test('a guest hitting the root is redirected to login via the projects route', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('projects.index'));

    $response = $this->get(route('projects.index'));

    $response->assertRedirect(route('login'));
});
