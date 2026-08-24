<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Csrf;
use App\Core\Security\Html;
use Throwable;

final class AuthController
{
    public function __construct(private readonly AuthFactory $factory) {}

    public function form(): Response
    {
        try {
            if ($this->factory->make()->user() !== null) {
                return Response::redirect('/account');
            }
            return $this->page('Sign in', $this->loginForm());
        } catch (Throwable) {
            return Response::json(['error' => 'Authentication is unavailable.'], 503);
        }
    }

    public function login(Request $request): Response
    {
        $email = trim((string) ($request->body['email'] ?? ''));
        $password = (string) ($request->body['password'] ?? '');
        if (strlen($email) > 254 || strlen($password) > 1024) {
            return $this->page('Sign in', $this->loginForm('Unable to sign in with those credentials.'), 422);
        }
        try {
            $result = $this->factory->make()->login($email, $password, $request->remoteAddress);
            return $result->success ? Response::redirect('/account') : $this->page('Sign in', $this->loginForm($result->message), 422);
        } catch (Throwable) {
            return $this->page('Sign in', $this->loginForm('Authentication is temporarily unavailable.'), 503);
        }
    }

    public function account(): Response
    {
        try {
            $user = $this->factory->make()->user();
            if ($user === null) {
                return Response::redirect('/login', 302);
            }
            return $this->page('Account', '<p>Signed in as <strong>' . Html::escape((string) $user['name']) . '</strong> (' . Html::escape((string) $user['email']) . ').</p><form method="post" action="/logout">' . $this->csrf() . '<button type="submit">Sign out</button></form>');
        } catch (Throwable) {
            return Response::json(['error' => 'Authentication is unavailable.'], 503);
        }
    }

    public function logout(): Response
    {
        try {
            $this->factory->make()->logout();
        } catch (Throwable) {
            (new NativeSessionStore())->invalidate();
        }
        return Response::redirect('/login');
    }

    private function loginForm(?string $error = null): string
    {
        $message = $error === null ? '' : '<p class="error">' . Html::escape($error) . '</p>';
        return $message . '<form method="post" action="/login">' . $this->csrf() . '<label>Email<input required type="email" name="email" maxlength="254" autocomplete="username"></label><label>Password<input required type="password" name="password" maxlength="1024" autocomplete="current-password"></label><button type="submit">Sign in</button></form>';
    }

    private function page(string $title, string $content, int $status = 200): Response
    {
        return Response::html('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . Html::escape($title) . ' — SEO Tracker</title><link rel="stylesheet" href="/assets/installer.css"></head><body><main class="card"><p><strong>SEO Tracker</strong></p><h1>' . Html::escape($title) . '</h1>' . $content . '</main></body></html>', $status);
    }

    private function csrf(): string
    {
        return '<input type="hidden" name="_token" value="' . Html::escape(Csrf::token()) . '">';
    }
}
