<?php

namespace HeyBug\Tests;

use HeyBug\Support\DataFilter;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

class DataFilterTest extends TestCase
{
    public function test_it_filters_password_fields(): void
    {
        $filter = new DataFilter(['*password*']);

        $data = $filter->filter([
            'username' => 'john',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $this->assertEquals('john', $data['username']);
        $this->assertEquals('[FILTERED]', $data['password']);
        $this->assertEquals('[FILTERED]', $data['password_confirmation']);
    }

    public function test_it_filters_token_fields(): void
    {
        $filter = new DataFilter(['*token*']);

        $data = $filter->filter([
            'access_token' => 'abc123',
            'refresh_token' => 'xyz789',
            'api_token' => 'secret',
        ]);

        $this->assertEquals('[FILTERED]', $data['access_token']);
        $this->assertEquals('[FILTERED]', $data['refresh_token']);
        $this->assertEquals('[FILTERED]', $data['api_token']);
    }

    public function test_it_filters_nested_arrays(): void
    {
        $filter = new DataFilter(['*password*', '*secret*']);

        $data = $filter->filter([
            'user' => [
                'name' => 'John',
                'password' => 'secret123',
                'settings' => [
                    'api_secret' => 'hidden',
                ],
            ],
        ]);

        $this->assertEquals('John', $data['user']['name']);
        $this->assertEquals('[FILTERED]', $data['user']['password']);
        $this->assertEquals('[FILTERED]', $data['user']['settings']['api_secret']);
    }

    public function test_it_handles_uploaded_files(): void
    {
        $filter = new DataFilter([]);

        $file = UploadedFile::fake()->create('document.pdf', 100);

        $data = $filter->filter([
            'name' => 'Report',
            'file' => $file,
        ]);

        $this->assertEquals('Report', $data['name']);
        $this->assertEquals('[FILE]', $data['file']);
    }

    public function test_it_is_case_insensitive(): void
    {
        $filter = new DataFilter(['*password*']);

        $data = $filter->filter([
            'PASSWORD' => 'secret',
            'Password' => 'secret',
            'pAsSwOrD' => 'secret',
        ]);

        $this->assertEquals('[FILTERED]', $data['PASSWORD']);
        $this->assertEquals('[FILTERED]', $data['Password']);
        $this->assertEquals('[FILTERED]', $data['pAsSwOrD']);
    }

    public function test_it_handles_empty_arrays(): void
    {
        $filter = new DataFilter(['*password*']);

        $this->assertEquals([], $filter->filter([]));
    }

    public function test_it_preserves_non_matching_keys(): void
    {
        $filter = new DataFilter(['*password*']);

        $data = $filter->filter([
            'email' => 'john@example.com',
            'name' => 'John Doe',
            'age' => 30,
        ]);

        $this->assertEquals('john@example.com', $data['email']);
        $this->assertEquals('John Doe', $data['name']);
        $this->assertEquals(30, $data['age']);
    }

    public function test_it_filters_with_multiple_patterns(): void
    {
        $filter = new DataFilter([
            '*password*',
            '*token*',
            '*secret*',
            '*key*',
            '*auth*',
        ]);

        $data = $filter->filter([
            'api_key' => 'abc',
            'auth_token' => 'xyz',
            'client_secret' => '123',
            'password' => 'pass',
            'username' => 'john',
        ]);

        $this->assertEquals('[FILTERED]', $data['api_key']);
        $this->assertEquals('[FILTERED]', $data['auth_token']);
        $this->assertEquals('[FILTERED]', $data['client_secret']);
        $this->assertEquals('[FILTERED]', $data['password']);
        $this->assertEquals('john', $data['username']);
    }

    public function test_it_handles_numeric_keys(): void
    {
        $filter = new DataFilter(['*password*']);

        $data = $filter->filter([
            0 => 'first',
            1 => 'second',
            'password' => 'secret',
        ]);

        $this->assertEquals('first', $data[0]);
        $this->assertEquals('second', $data[1]);
        $this->assertEquals('[FILTERED]', $data['password']);
    }
}
