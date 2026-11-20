<?php

declare(strict_types=1);

// routes/web.php of the Laravel app of the CI job `works-with`: the page reads the request of
// check.php, the user of the test and the users in its transaction, adds a user to it, and sends
// a mail to its fake. A second route throws.

use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\StreamedResponse;

// curl sends and reads the cookies as they are; the app would encrypt them.
EncryptCookies::except(['a', 'b', 'c']);

Route::post('/works-with', static function (Request $request): StreamedResponse {
    User::query()->create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret']);
    Mail::to('ada@example.com')->send(new class extends Mailable
    {
        public function content(): Content
        {
            return new Content(htmlString: 'Hello Ada');
        }
    });
    $response = response()->stream(static function () use ($request): void {
        echo json_encode([
            'method' => $request->method(),
            'path' => $request->getPathInfo(),
            'host' => $request->getHttpHost(),
            'ip' => $request->ip(),
            'query' => $request->query('q'),
            'form' => $request->post('name'),
            'cookie' => $request->cookie('c'),
            'token' => $request->header('X-Token'),
            'user' => $request->user()?->name,
            'users' => User::count(),
        ]);
    }, 201, ['X-Answer' => 'yes']);
    $response->headers->setCookie(Cookie::create('a', '1'));
    $response->headers->setCookie(Cookie::create('b', '2'));

    return $response;
});

Route::post('/works-with/throw', static fn (): never => throw new RuntimeException('thrown by the app'));
