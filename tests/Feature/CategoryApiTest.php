<?php

namespace Tests\Feature;

use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    public function test_validation_errors_are_returned_as_json_without_an_accept_header(): void
    {
        $response = $this->call(
            'POST',
            '/api/categories',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{}'
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name', 'slug', 'status']);
    }
}
