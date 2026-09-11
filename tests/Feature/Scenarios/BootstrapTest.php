<?php

it('loads the homepage', function () {
    $this->get('/')->assertOk();
});

it('redirects an unauthenticated visitor from /admin to the login page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});
