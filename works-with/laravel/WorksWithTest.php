<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use SorgeIt\HttpServer\Server;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;
use Throwable;

require_once __DIR__.'/../laravel.php';
require_once __DIR__.'/../check.php';

final class WorksWithTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_sees_the_user_the_transaction_and_the_fake_of_the_test(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create(['name' => 'Ada']));
        $server = new Server(laravelHandler($this->app), onError: static fn (Throwable $e): never => throw $e);

        try {
            $output = fetch($server);
        } finally {
            $server->close();
        }

        // check() ends the run with exit code 1 when the page read something else.
        Mail::assertSentCount(1);
        $this->assertDatabaseHas('users', ['name' => 'Bob']);
        check('laravel/framework', $server, $output, ['user' => 'Ada', 'users' => 2]);
    }

    public function test_an_exception_of_the_app_reaches_on_error_only_without_the_exception_handling(): void
    {
        $errors = [];
        $server = new Server(laravelHandler($this->app), onError: static function (Throwable $e) use (&$errors): void {
            $errors[] = $e->getMessage();
        });

        try {
            $handled = fetch($server, '/works-with/throw');
            $this->withoutExceptionHandling([NotFoundHttpException::class]);
            $missing = fetch($server, '/favicon.ico');
            fetch($server, '/works-with/throw');
        } finally {
            $server->close();
        }

        $this->assertStringStartsWith('HTTP/1.1 500 ', $handled);
        $this->assertStringStartsWith('HTTP/1.1 404 ', $missing);
        $this->assertSame(['thrown by the app'], $errors);
    }
}
